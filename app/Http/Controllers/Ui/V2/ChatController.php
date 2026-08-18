<?php

namespace App\Http\Controllers\Ui\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Tobuli\Entities\Chat;
use Tobuli\Entities\ChatMessage;
use Tobuli\Entities\Device;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Services\ChatService;

/**
 * TalentusPro · ChatController (ui/v2)
 * -----------------------------------------------------------------
 * Chat con dispositivos. Reutiliza las entidades Chat/ChatMessage y el
 * ChatService legacy, pero devuelve JSON (el legacy devolvía parciales
 * Blade) para un panel v2 de 2 columnas (lista con no-leídos + hilo con
 * polling). Enviar mensaje usa ChatMessage->send() (misma tubería que el
 * legacy → socket/redis + BD). Permisos: módulo 'chat'.
 * -----------------------------------------------------------------
 */
class ChatController extends BaseController
{
    private ChatService $chatService;

    protected function afterAuth($user)
    {
        $this->chatService = app(ChatService::class);
    }

    /** Gate de módulo (perm 'chat'). La PERTENENCIA al room se valida aparte
     *  con checkException('chats', …, $chat) → ChatPolicy::ownership, igual
     *  que el legacy ('chats' SÍ está mapeado a Chat::class en el Manager). */
    private function gate(string $action = 'view'): void
    {
        if (! $this->user->perm('chat', $action)) {
            abort(403);
        }
    }

    public function page()
    {
        if (! $this->user->perm('chat', 'view')) {
            abort(403);
        }

        return View::make('ui.v2.pages.chat', ['pageTitle' => trans('front.chat')]);
    }

    /** Dispositivos chateables con conteo de no-leídos (columna izquierda). */
    public function conversations(Request $request): JsonResponse
    {
        $this->gate('view');

        $items = $this->user->devices()
            ->search($request->get('s'))
            ->chatable()
            ->withCount(['messages' => function ($q) {
                $q->unread()->whereChattable($this->user);
            }])
            ->clearOrdersBy()
            ->orderBy('messages_count', 'desc')
            ->orderBy('devices.name')
            ->limit(50)
            ->get();

        $rows = [];
        foreach ($items as $d) {
            $rows[] = [
                'device_id' => (int) $d->id,
                'name'      => (string) $d->name,
                'unread'    => (int) ($d->messages_count ?? 0),
            ];
        }

        return response()->json([
            'ok'     => true,
            'items'  => $rows,
            'unread' => (int) $this->chatService->getUnreadMessageCount($this->user),
        ]);
    }

    /** Abre (o crea) el room de un dispositivo y devuelve sus mensajes. */
    public function open(Request $request, $deviceId): JsonResponse
    {
        $this->gate('edit');

        $device = Device::find((int) $deviceId);
        if (! $device) {
            abort(404);
        }
        $this->checkException('devices', 'show', $device);

        $chat = Chat::getRoomByDevice($device);
        $chat->addParticipant($this->user);
        $this->chatService->markAsRead($chat, $this->user);

        return response()->json([
            'ok'       => true,
            'chat_id'  => (int) $chat->id,
            'name'     => (string) $device->name,
            'messages' => $this->mapMessages($chat->getLastMessages()->items()),
        ]);
    }

    /** Mensajes nuevos desde ?time= (polling del hilo abierto). */
    public function messages(Request $request, $chatId): JsonResponse
    {
        $this->gate('view');
        $chat = Chat::find((int) $chatId);
        if (! $chat) {
            abort(404);
        }
        // Paridad legacy ChatController@57-68: solo participantes del room
        // (ChatPolicy::ownership). Sin esto cualquier usuario leería cualquier
        // chat por id (IDOR).
        $this->checkException('chats', 'show', $chat);

        $time = (int) $request->get('time');
        $q = $chat->messages();
        if ($time) {
            $q->since($time);
        }
        $msgs = $q->orderBy('created_at', 'asc')->limit(100)->get();

        // Marca como leído al traer mensajes (el usuario está viendo el hilo)
        $this->chatService->markAsRead($chat, $this->user);

        return response()->json([
            'ok'       => true,
            'time'     => time(),
            'messages' => $this->mapMessages($msgs),
        ]);
    }

    public function send(Request $request, $chatId): JsonResponse
    {
        $this->gate('edit');
        $chat = Chat::find((int) $chatId);
        if (! $chat) {
            abort(404);
        }
        // Paridad legacy ChatController@156: escribir exige ser participante.
        $this->checkException('chats', 'update', $chat);

        $text = trim((string) $request->get('message'));
        if ($text === '') {
            return response()->json(['ok' => false, 'errors' => ['message' => trans('validation.attributes.message')]], 422);
        }

        try {
            (new ChatMessage())->setTo(null, $chat)->setFrom($this->user)->setContent($text)->send();
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->getErrors()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function unread(): JsonResponse
    {
        $this->gate('view');

        return response()->json(['ok' => true, 'count' => (int) $this->chatService->getUnreadMessageCount($this->user)]);
    }

    /* ----------------------------------------------------------------- */

    /** ChatMessage[] → payload plano {id, content, mine, sender, time}. */
    private function mapMessages($messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            $out[] = [
                'id'      => (int) $m->id,
                'content' => (string) $m->content,
                'mine'    => (bool) $m->isMyMessage($this->user),
                'sender'  => (string) $m->sender_name,
                'time'    => (string) $m->created_at,
            ];
        }

        return $out;
    }
}
