<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use Tobuli\Services\NotificationsTrayService;

/**
 * Bandeja de notificaciones para la app móvil (user_api_hash), con el mismo estado
 * «leído» que la UI v2 (Ui\V2\ShellController@notifications / notificationsSeen).
 */
class NotificationsTrayController extends Controller
{
    /**
     * Query: before_id (scroll infinito: eventos más viejos que ese id).
     * Responde notifications[], has_more, seen_id (null en cuentas demo) y unread.
     */
    public function apiIndex(NotificationsTrayService $tray)
    {
        $page = $tray->page($this->user, (int) request()->get('before_id', 0));

        return ['status' => 1, 'unread' => $tray->unreadCount($this->user)] + $page;
    }

    /** Solo el contador de no leídos (para el badge), sin cargar la lista. */
    public function apiUnread(NotificationsTrayService $tray)
    {
        return [
            'status'  => 1,
            'unread'  => $tray->unreadCount($this->user),
            'seen_id' => $tray->seenId($this->user),
        ];
    }

    /** «Marcar todo como leído» hasta last_id (solo avanza). */
    public function apiSeen(NotificationsTrayService $tray)
    {
        $result = $tray->markSeen($this->user, (int) request()->get('last_id', 0));

        return ['status' => $result['ok'] ? 1 : 0] + $result;
    }
}
