<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Services\Ai\AgentException;
use App\Services\Ai\AiAgent;
use App\Services\Ai\AiQuota;
use App\Services\Ai\ClaudeException;
use App\Services\Ai\AiToolRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tobuli\Entities\AiActionLog;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tobuli\Entities\AiConversation;

class AiAssistantController extends Controller
{
    /** Sugerencias de bienvenida con unidades reales del usuario. */
    public function suggestions(Request $request)
    {
        $user = $request->user();

        // La placa es mas corta que el nombre completo para una sugerencia.
        $names = $user->devices()->limit(2)->get(['devices.name', 'devices.plate_number'])
            ->map(function ($device) {
                return trim((string) $device->plate_number) ?: $device->name;
            })->all();
        $first = $names[0] ?? 'mi unidad';

        $suggestions = [
            'Excesos de 80 km/h de esta semana',
            '¿Qué unidad paró más tiempo ayer?',
            "¿Dónde está $first?",
            '¿Cuántas unidades están offline ahora?',
            'Resumen de km de la semana pasada',
            'Eventos de hoy',
            '¿Cómo creo una geocerca?',
        ];

        if (isset($names[1])) {
            $suggestions[] = "¿Por qué {$names[1]} está offline?";
        }

        return response()->json([
            'suggestions' => $suggestions,
            'remaining' => AiQuota::remaining($user),
        ]);
    }

    public function conversations(Request $request)
    {
        $items = AiConversation::where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get(['id', 'title', 'updated_at']);

        return response()->json(['conversations' => $items]);
    }

    /** Conversacion agrupada en turnos para dibujarla en el panel. */
    public function show(Request $request, $id)
    {
        $conversation = $this->findConversation($request, $id);

        $turns = [];
        foreach ($conversation->messages()->where('visible', true)->get() as $message) {
            $display = $message->display ?: [];

            if ($message->role === 'user') {
                $turns[] = ['role' => 'user', 'text' => $display['text'] ?? ''];
                continue;
            }

            $last = count($turns) - 1;
            if ($last < 0 || $turns[$last]['role'] !== 'assistant') {
                $turns[] = ['role' => 'assistant', 'text' => '', 'sources' => [], 'actions' => []];
                $last++;
            }

            $text = trim($display['text'] ?? '');
            if ($text !== '') {
                $turns[$last]['text'] .= ($turns[$last]['text'] !== '' ? "\n\n" : '') . $text;
            }
            $turns[$last]['sources'] = array_merge($turns[$last]['sources'], $display['sources'] ?? []);
            $turns[$last]['actions'] = array_merge($turns[$last]['actions'], $display['actions'] ?? []);
        }

        return response()->json([
            'id' => $conversation->id,
            'title' => $conversation->title,
            'turns' => $turns,
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $this->findConversation($request, $id)->delete();

        return response()->json(['status' => 1]);
    }

    /**
     * Pregunta al asistente. Responde con eventos SSE:
     * conversation, delta, source, action, done, error.
     */
    public function chat(Request $request)
    {
        $this->validate($request, [
            'message' => 'required|string|max:2000',
            'conversation_id' => 'nullable|integer',
            'screen' => 'nullable|string|max:60',
        ]);

        $user = $request->user();

        if (AiQuota::budgetExceeded()) {
            return response()->json(['message' => trans('front.ai_disabled')], 429);
        }

        if (AiQuota::remaining($user) === 0) {
            return response()->json(['message' => trans('front.ai_quota_reached')], 429);
        }

        $conversation = $request->input('conversation_id')
            ? $this->findConversation($request, $request->input('conversation_id'))
            : AiConversation::create(['user_id' => $user->id]);

        $question = trim($request->input('message'));
        $context = ['screen' => $request->input('screen')];

        // Liberar la sesion para no bloquear otras peticiones mientras responde.
        if ($request->hasSession()) {
            $request->session()->save();
        }

        return new StreamedResponse(function () use ($user, $conversation, $question, $context) {
            @set_time_limit(300);
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }

            $emit = function (string $event, array $data) {
                echo "event: $event\n";
                echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
                @flush();
            };

            $emit('conversation', ['id' => $conversation->id]);

            try {
                $result = (new AiAgent($user))->ask($conversation, $question, $context, $emit);

                $emit('done', [
                    'status' => $result['status'],
                    'remaining' => AiQuota::remaining($user),
                ]);
            } catch (AgentException $e) {
                $emit('error', ['code' => $e->getMessage(), 'message' => $e->getMessage() === 'conversation_too_long'
                    ? 'Esta conversación ya es muy larga. Inicia una nueva para seguir.'
                    : trans('front.ai_error')]);
            } catch (ClaudeException $e) {
                Log::warning('IA API error', ['type' => $e->type, 'status' => $e->status, 'message' => $e->getMessage()]);
                $emit('error', ['code' => $e->type, 'message' => trans('front.ai_error')]);
            } catch (\Throwable $e) {
                Log::error('IA error: ' . $e->getMessage(), ['file' => $e->getFile() . ':' . $e->getLine()]);
                $emit('error', ['code' => 'internal', 'message' => trans('front.ai_error')]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /** "Explicar este reporte": resume el texto del reporte HTML que el usuario tiene abierto. */
    public function explainReport(Request $request)
    {
        $this->validate($request, [
            'title' => 'nullable|string|max:255',
            'text' => 'required|string|max:200000',
        ]);

        $user = $request->user();

        if (!\App\Services\Ai\AiFeatures::enabled($user, 'explain_report')) {
            return response()->json(['message' => trans('front.ai_disabled')], 403);
        }
        if (AiQuota::budgetExceeded()) {
            return response()->json(['message' => trans('front.ai_disabled')], 429);
        }
        if (AiQuota::remaining($user) === 0) {
            return response()->json(['message' => trans('front.ai_quota_reached')], 429);
        }

        \Formatter::byUser($user);

        try {
            $text = \App\Services\Ai\AiFeatures::explainReport($user, (string) $request->input('title'), $request->input('text'));
        } catch (\Throwable $e) {
            Log::warning('IA explain error: ' . $e->getMessage());
            return response()->json(['message' => trans('front.ai_error')], 502);
        }

        return response()->json([
            'text' => $text !== '' ? $text : 'No puedo analizar este reporte.',
            'remaining' => AiQuota::remaining($user),
        ]);
    }

    /** Bloque "Resumen del asistente" del dashboard. */
    public function dashboardSummary(Request $request)
    {
        $user = $request->user();

        if (!\App\Services\Ai\AiFeatures::enabled($user, 'dashboard')) {
            return response()->json(['message' => trans('front.ai_disabled')], 403);
        }

        try {
            return response()->json(\App\Services\Ai\AiFeatures::dashboardSummary($user));
        } catch (\Throwable $e) {
            Log::warning('IA dashboard error: ' . $e->getMessage());
            return response()->json(['message' => trans('front.ai_error')], 502);
        }
    }

    /** Descarga de un reporte generado por exportar_reporte (solo su autor, 1 hora). */
    public function download(Request $request, $token)
    {
        $file = Cache::get('tpai.download.' . $token);

        if (!$file || (int) $file['user_id'] !== (int) $request->user()->id || !is_file($file['path'])) {
            abort(404);
        }

        return response()->download($file['path'], $file['name']);
    }

    /** Ejecuta una accion preparada por el asistente despues de que el usuario confirma. */
    public function confirm(Request $request, $token)
    {
        $pending = Cache::pull('tpai.pending.' . $token);
        $user = $request->user();

        if (!$pending || (int) $pending['user_id'] !== (int) $user->id) {
            return response()->json(['message' => 'Esta acción ya no está disponible. Pídela de nuevo al asistente.'], 410);
        }

        /** @var \App\Services\Ai\Tools\Actions\ActionTool $class */
        $class = $pending['class'];

        if (!AiToolRegistry::canAct($user) || !$class::allowed($user, $pending['params'])) {
            return response()->json(['message' => trans('front.dont_have_permission')], 403);
        }

        \Formatter::byUser($user);

        try {
            $message = $class::execute($user, $pending['params']);
            $status = 'done';
        } catch (\App\Services\Ai\ToolException $e) {
            $message = $e->getMessage();
            $status = 'failed';
        } catch (\Throwable $e) {
            Log::error('IA action error: ' . $e->getMessage(), ['action' => $pending['action']]);
            $message = 'No se pudo completar la acción. Inténtalo desde la pantalla correspondiente.';
            $status = 'failed';
        }

        $this->audit($request, $pending, $status, $message);

        return response()->json(['status' => $status, 'message' => $message], $status === 'done' ? 200 : 422);
    }

    public function cancel(Request $request, $token)
    {
        $pending = Cache::pull('tpai.pending.' . $token);

        if ($pending && (int) $pending['user_id'] === (int) $request->user()->id) {
            $this->audit($request, $pending, 'cancelled', null);
        }

        return response()->json(['status' => 'cancelled', 'message' => 'Acción cancelada.']);
    }

    private function audit(Request $request, array $pending, string $status, ?string $result)
    {
        AiActionLog::create([
            'user_id' => $request->user()->id,
            'conversation_id' => $request->input('conversation_id'),
            'action' => $pending['action'],
            'title' => mb_substr($pending['title'], 0, 255),
            'params' => $pending['params'],
            'status' => $status,
            'result' => $result,
            'ip' => $request->ip(),
        ]);
    }

    private function findConversation(Request $request, $id): AiConversation
    {
        return AiConversation::where('user_id', $request->user()->id)->findOrFail((int) $id);
    }
}
