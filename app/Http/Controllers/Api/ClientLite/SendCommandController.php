<?php


namespace App\Http\Controllers\Api\ClientLite;


use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Tobuli\Exceptions\ValidationException;
use Tobuli\Services\Commands\CommandService;
use Tobuli\Services\Commands\SendCommandService;


class SendCommandController extends Controller
{
    public function view(Request $request)
    {
        $this->checkException('send_command', 'view');

        $validator = Validator::make($request->all(), [
            'connection' => 'required|in:' . implode(',', [
                SendCommandService::CONNECTION_SMS,
                SendCommandService::CONNECTION_GPRS]),
            'device_id' => 'required'
        ]);

        if ($validator->fails())
            throw new ValidationException($validator->messages());

        $devices = $this->user->devices()
            ->filter($request->all())
            ->get();

        if ($request->get('connection') == SendCommandService::CONNECTION_SMS)
            return response()->json([
                'data' => $this->commandService->getSmsCommands($devices, true)
            ]);

        return response()->json([
            'data' => $this->commandService->getGprsCommands($devices, true)
        ]);
    }

    public function store(Request $request)
    {
        $this->checkException('send_command', 'view');

        $validator = Validator::make($request->all(), [
            'connection' => 'required|in:' . implode(',', [
                    SendCommandService::CONNECTION_SMS,
                    SendCommandService::CONNECTION_GPRS]),
            'device_id' => 'required',
            'type'      => 'required'
        ]);

        if ($validator->fails())
            throw new ValidationException($validator->messages());

        $devices = $this->user->devices()
            ->filter($request->all())
            ->get();

        if ($devices->isEmpty()) {
            throw new ValidationException(['device_id' => trans('global.not_found')]);
        }

        $commandService = new CommandService($this->user);
        $commandService->validateGPRS($devices, $request->all());

        $sendCommandService = new SendCommandService();
        $responses = $sendCommandService->gprs($devices, $request->all(), $this->user);

        $errors = $responses
            ->filter(function ($response) {
                return $response['status'] == 0;
            })
            ->map(function ($response) {
                return "{$response['device']}: {$response['error']}";
            });

        if ($errors->isNotEmpty()) {
            return ['status' => 0, 'errors' => $errors];
        }

        return ['status' => 1, 'message' => trans('front.command_sent')];
    }
}