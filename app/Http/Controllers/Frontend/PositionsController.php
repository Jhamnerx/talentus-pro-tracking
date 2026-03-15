<?php

namespace App\Http\Controllers\Frontend;

use App\Console\PositionsStack;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Response;
use Tobuli\Entities\Device;

class PositionsController extends Controller
{
    public function insert()
    {
        $input = Request::all();

        // Validate required fields
        $required = ['uniqueId', 'fixTime', 'latitude', 'longitude', 'speed', 'altitude', 'course', 'protocol'];
        $missing  = [];
        foreach ($required as $field) {
            if (!isset($input[$field]) || $input[$field] === '') {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            return Response::json(['status' => 0, 'message' => 'Missing params: ' . implode(', ', $missing)], 400);
        }

        // Validate numeric fields
        foreach (['latitude', 'longitude', 'speed', 'altitude', 'course'] as $field) {
            if (!is_numeric($input[$field])) {
                return Response::json(['status' => 0, 'message' => 'Invalid value for: ' . $field], 422);
            }
        }

        // Sanitize IMEI: only alphanumeric and hyphens
        $imei = preg_replace('/[^a-zA-Z0-9\-]/', '', $input['uniqueId']);
        if (empty($imei)) {
            return Response::json(['status' => 0, 'message' => 'Invalid uniqueId'], 422);
        }

        // Reject positions for unregistered devices
        if (!Device::where('imei', $imei)->exists()) {
            return Response::json(['status' => 0, 'message' => 'Unknown device'], 404);
        }

        // Accept fixTime as ISO string or numeric milliseconds timestamp
        $fixTime = is_numeric($input['fixTime'])
            ? (int) $input['fixTime']
            : strtotime($input['fixTime']) * 1000;

        if ($fixTime <= 0) {
            return Response::json(['status' => 0, 'message' => 'Invalid fixTime'], 422);
        }

        $data = [
            'fixTime'    => $fixTime,
            'valid'      => filter_var($input['valid'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'imei'       => $imei,
            'latitude'   => (float) $input['latitude'],
            'longitude'  => (float) $input['longitude'],
            'attributes' => is_array($input['attributes'] ?? null) ? $input['attributes'] : [],
            'speed'      => (float) $input['speed'],
            'altitude'   => (float) $input['altitude'],
            'course'     => (float) $input['course'],
            'protocol'   => $input['protocol'],
        ];

        (new PositionsStack())->add($data);

        return Response::json(['status' => 1, 'message' => 'Position inserted'], 200);
    }
}
