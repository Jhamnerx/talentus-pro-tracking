<!DOCTYPE html>
<html lang="{{ Language::iso() }}">

<head>
    @include('Frontend.Layouts.partials.head')
    <title>{{ trans('front.video_playback') }}</title>
    <style>
        /* ── RESET & BASE ─────────────────────────────────────────── */
        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            height: 100%;
            overflow: hidden;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
            font-size: 13px;
            background: #111;
            color: #e0e0e0;
        }

        button {
            font-family: inherit;
            cursor: pointer;
        }

        /* ── LAYOUT ──────────────────────────────────────────────── */
        .pb-wrap {
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        /* Left nav strip */
        .pb-nav {
            width: 82px;
            flex-shrink: 0;
            background: #0f3460;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding-top: 6px;
            gap: 2px;
            overflow-y: auto;
        }

        .pb-nav-item {
            width: 76px;
            padding: 8px 4px;
            border-radius: 4px;
            text-align: center;
            cursor: pointer;
            text-decoration: none;
            color: #7fa8c8;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            transition: background .15s, color .15s;
        }

        .pb-nav-item:hover {
            background: rgba(255, 255, 255, .08);
            color: #c8e0f0;
        }

        .pb-nav-item.active {
            background: #0a2240;
            color: #00cfaa;
        }

        .pb-nav-item i {
            font-size: 1.25rem;
        }

        .pb-nav-item span {
            font-size: .62rem;
            line-height: 1.3;
            word-break: break-word;
        }

        /* Filter sidebar */
        .pb-sidebar {
            width: 262px;
            flex-shrink: 0;
            background: #fafafa;
            color: #333;
            border-right: 1px solid #d0d0d0;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }

        .pb-sidebar-inner {
            padding: 12px 14px;
        }

        .pb-field {
            margin-bottom: 13px;
        }

        .pb-label {
            display: block;
            font-size: .73rem;
            color: #666;
            margin-bottom: 4px;
            font-weight: 600;
        }

        .pb-select,
        .pb-input {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #fff;
            font-size: .82rem;
            color: #333;
            outline: none;
        }

        .pb-select:focus,
        .pb-input:focus {
            border-color: #00897b;
        }

        .pb-time-row {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .pb-time-row .pb-input {
            flex: 1;
            padding: 5px 6px;
        }

        .pb-time-dash {
            color: #aaa;
            font-size: .9rem;
        }

        /* Mini Calendar */
        .pb-calendar {
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
            background: #fff;
            margin-bottom: 0;
        }

        .pb-cal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f0f0f0;
            padding: 5px 6px;
        }

        .pb-cal-nav {
            background: none;
            border: none;
            padding: 2px 5px;
            border-radius: 3px;
            font-size: .95rem;
            color: #555;
        }

        .pb-cal-nav:hover {
            background: #ddd;
        }

        .pb-cal-title {
            font-weight: 700;
            font-size: .8rem;
            color: #333;
        }

        .pb-cal-grid {
            padding: 4px 6px 6px;
        }

        .pb-cal-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            text-align: center;
            font-size: .65rem;
            color: #aaa;
            padding-bottom: 2px;
        }

        .pb-cal-days {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
        }

        .pb-cal-day {
            text-align: center;
            padding: 4px 1px;
            font-size: .75rem;
            border-radius: 3px;
            color: #444;
            cursor: pointer;
            user-select: none;
        }

        .pb-cal-day:hover {
            background: #e3f2fd;
            color: #1565c0;
        }

        .pb-cal-day.today {
            font-weight: 700;
            color: #1565c0;
        }

        .pb-cal-day.selected {
            background: #00897b;
            color: #fff;
            font-weight: 700;
        }

        .pb-cal-day.empty {
            cursor: default;
            pointer-events: none;
        }

        /* Search button */
        .pb-btn-search {
            width: 100%;
            padding: 9px;
            background: #00897b;
            color: #fff;
            border: none;
            border-radius: 4px;
            font-size: .88rem;
            font-weight: 600;
            margin-top: 6px;
        }

        .pb-btn-search:hover {
            background: #00796b;
        }

        .pb-btn-search:disabled {
            opacity: .45;
            cursor: default;
        }

        /* ── MAIN ─────────────────────────────────────────────────── */
        .pb-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Toolbar */
        .pb-toolbar {
            height: 38px;
            background: #1a1a30;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            padding: 0 10px;
            gap: 6px;
            border-bottom: 1px solid #2a2a45;
        }

        .pb-tool-btn {
            background: none;
            border: 1px solid #444;
            color: #999;
            padding: 3px 9px;
            border-radius: 3px;
            font-size: .78rem;
        }

        .pb-tool-btn:hover {
            background: #2a2a45;
            color: #ddd;
        }

        .pb-tool-btn.active {
            background: #00897b;
            border-color: #00897b;
            color: #fff;
        }

        .pb-tool-sep {
            width: 1px;
            height: 20px;
            background: #333;
            margin: 0 2px;
        }

        .pb-status-bar {
            margin-left: auto;
            font-size: .73rem;
            color: #666;
        }

        .pb-status-bar.info {
            color: #64b5f6;
        }

        .pb-status-bar.ok {
            color: #66bb6a;
        }

        .pb-status-bar.error {
            color: #ef5350;
        }

        /* Video grid */
        .pb-grid {
            flex: 1;
            display: grid;
            overflow: hidden;
            min-height: 0;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: 1fr 1fr;
            gap: 2px;
            background: #2a2a2a;
        }

        .pb-grid.single-view {
            grid-template-columns: 1fr;
            grid-template-rows: 1fr;
        }

        .pb-grid.single-view .pb-panel:not(.active) {
            display: none;
        }

        .pb-panel {
            position: relative;
            background: #000;
            overflow: hidden;
        }

        .pb-panel-title {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            z-index: 2;
            background: rgba(0, 0, 0, .55);
            color: #888;
            font-size: .7rem;
            padding: 3px 8px;
            pointer-events: none;
        }

        .pb-panel-title.live-badge::after {
            content: ' ● LIVE';
            color: #4caf50;
            font-weight: 700;
        }

        .pb-panel video {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }

        .pb-panel-overlay {
            position: absolute;
            inset: 0;
            z-index: 1;
            background: rgba(0, 0, 0, .65);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #666;
            font-size: .82rem;
        }

        .pb-panel-overlay.hidden {
            display: none;
        }

        .pb-panel-play {
            width: 48px;
            height: 48px;
            background: rgba(255, 255, 255, .12);
            border: 2px solid rgba(255, 255, 255, .25);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: .18s;
        }

        .pb-panel-play:hover {
            background: rgba(255, 255, 255, .22);
            border-color: rgba(255, 255, 255, .55);
        }

        .pb-panel-play i {
            font-size: 1.3rem;
            color: #ddd;
            margin-left: 4px;
        }

        /* Spinner */
        @keyframes pb-spin {
            to {
                transform: rotate(360deg);
            }
        }

        .pb-spinner {
            width: 30px;
            height: 30px;
            border: 3px solid #444;
            border-top-color: #00cfaa;
            border-radius: 50%;
            animation: pb-spin .7s linear infinite;
            margin-bottom: 8px;
        }

        /* ── BOTTOM PANEL ─────────────────────────────────────────── */
        .pb-bottom {
            height: 195px;
            flex-shrink: 0;
            background: #15152a;
            border-top: 2px solid #2a2a45;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .pb-tabs-bar {
            display: flex;
            background: #111125;
            border-bottom: 1px solid #2a2a45;
            flex-shrink: 0;
        }

        .pb-tab {
            padding: 7px 16px;
            cursor: pointer;
            font-size: .76rem;
            color: #666;
            border-bottom: 2px solid transparent;
            white-space: nowrap;
            user-select: none;
        }

        .pb-tab:hover {
            color: #aaa;
        }

        .pb-tab.active {
            color: #00cfaa;
            border-bottom-color: #00cfaa;
        }

        .pb-tab-pane {
            display: none;
            flex: 1;
            overflow: auto;
            min-height: 0;
        }

        .pb-tab-pane.active {
            display: flex;
            flex-direction: column;
        }

        /* Timeline */
        .pb-tl-wrap {
            padding: 6px 10px;
            flex: 1;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .pb-tl-toolbar {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }

        .pb-tl-btn {
            background: #1f1f38;
            border: 1px solid #3a3a55;
            color: #888;
            padding: 3px 9px;
            border-radius: 3px;
            font-size: .73rem;
        }

        .pb-tl-btn:hover {
            background: #2a2a48;
            color: #ddd;
        }

        .pb-tl-chart {
            flex: 1;
            overflow: hidden;
            position: relative;
            min-height: 0;
        }

        .pb-tl-axis {
            display: flex;
            position: relative;
            height: 18px;
            border-bottom: 1px solid #2a2a45;
            margin-left: 36px;
        }

        .pb-tl-hour {
            position: absolute;
            font-size: .6rem;
            color: #555;
            transform: translateX(-50%);
            top: 2px;
        }

        .pb-tl-rows {
            position: relative;
            margin-left: 36px;
        }

        .pb-tl-ch-row {
            display: flex;
            align-items: center;
            height: 20px;
        }

        .pb-tl-ch-lbl {
            width: 36px;
            margin-left: -36px;
            font-size: .6rem;
            color: #555;
            text-align: right;
            padding-right: 4px;
            flex-shrink: 0;
        }

        .pb-tl-ch-bar {
            flex: 1;
            position: relative;
            height: 14px;
            background: rgba(255, 255, 255, .03);
            border-radius: 2px;
        }

        .pb-tl-seg {
            position: absolute;
            height: 100%;
            border-radius: 2px;
            cursor: pointer;
            opacity: .8;
            min-width: 3px;
        }

        .pb-tl-seg:hover {
            opacity: 1;
        }

        /* File list */
        .pb-file-scroll {
            flex: 1;
            overflow-y: auto;
        }

        .pb-file-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .76rem;
        }

        .pb-file-table th {
            background: #1a1a30;
            color: #777;
            font-weight: 600;
            padding: 5px 8px;
            text-align: left;
            border-bottom: 1px solid #2a2a45;
            position: sticky;
            top: 0;
            z-index: 1;
            white-space: nowrap;
        }

        .pb-file-table td {
            padding: 4px 8px;
            border-bottom: 1px solid #1e1e30;
            color: #bbb;
            white-space: nowrap;
        }

        .pb-file-table tr:hover td {
            background: #1c1c30;
        }

        .pb-file-table tr.selected td {
            background: #132a1e;
        }

        .pb-file-btn {
            background: #14503a;
            border: none;
            color: #00cfaa;
            padding: 2px 9px;
            border-radius: 3px;
            font-size: .71rem;
        }

        .pb-file-btn:hover {
            background: #00897b;
            color: #fff;
        }

        /* Placeholder tabs */
        .pb-tab-placeholder {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #444;
            font-size: .82rem;
            gap: 6px;
        }
    </style>
</head>

<body>

    <div class="pb-wrap">

        {{-- ── LEFT NAV ────────────────────────────────────────────── --}}
        <nav class="pb-nav">
            <a class="pb-nav-item" href="#" data-nav="alarm_clip">
                <i class="fa fa-film"></i>
                <span>{{ trans('front.alarm_clip') }}</span>
            </a>
            <a class="pb-nav-item active" href="#" data-nav="playback">
                <i class="fa fa-play-circle"></i>
                <span>{{ trans('front.video_playback') }}</span>
            </a>
            <a class="pb-nav-item" href="#" data-nav="track">
                <i class="fa fa-map-marker"></i>
                <span>{{ trans('front.track') }}</span>
            </a>
            <a class="pb-nav-item" href="#" data-nav="trip">
                <i class="fa fa-road"></i>
                <span>{{ trans('front.trip') }}</span>
            </a>
            <a class="pb-nav-item" href="#" data-nav="capture">
                <i class="fa fa-camera"></i>
                <span>{{ trans('front.snapshot') }}</span>
            </a>
            <a class="pb-nav-item" href="#" data-nav="recording">
                <i class="fa fa-circle" style="color:#e74c3c"></i>
                <span>{{ trans('front.recording') }}</span>
            </a>
            <a class="pb-nav-item" href="#" data-nav="alarm">
                <i class="fa fa-bell"></i>
                <span>{{ trans('front.alerts') }}</span>
            </a>
        </nav>

        {{-- ── FILTER SIDEBAR ──────────────────────────────────────── --}}
        <aside class="pb-sidebar">
            <div class="pb-sidebar-inner">

                {{-- Vehicle --}}
                <div class="pb-field">
                    <label class="pb-label">{{ trans('front.select_vehicle') }}</label>
                    <select class="pb-select" id="pb-device">
                        <option value="">{{ trans('front.please_select') }}</option>
                        @foreach ($devices as $device)
                            <option value="{{ $device->id }}" data-protocol="{{ $device->protocol }}">
                                {{ $device->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Date calendar --}}
                <div class="pb-field">
                    <label class="pb-label">{{ trans('front.date') }}</label>
                    <div class="pb-calendar" id="pb-calendar">
                        <div class="pb-cal-head">
                            <button class="pb-cal-nav" id="cal-prev-yr" title="Previous year">«</button>
                            <button class="pb-cal-nav" id="cal-prev-mo" title="Previous month">‹</button>
                            <span class="pb-cal-title" id="cal-title"></span>
                            <button class="pb-cal-nav" id="cal-next-mo" title="Next month">›</button>
                            <button class="pb-cal-nav" id="cal-next-yr" title="Next year">»</button>
                        </div>
                        <div class="pb-cal-grid">
                            <div class="pb-cal-weekdays" id="cal-weekdays"></div>
                            <div class="pb-cal-days" id="cal-days"></div>
                        </div>
                    </div>
                </div>

                {{-- Time range --}}
                <div class="pb-field">
                    <label class="pb-label">{{ trans('front.time') }}</label>
                    <div class="pb-time-row">
                        <i class="fa fa-clock-o" style="color:#aaa; flex-shrink:0"></i>
                        <input type="time" class="pb-input" id="pb-time-start" value="00:00" step="1">
                        <span class="pb-time-dash">–</span>
                        <input type="time" class="pb-input" id="pb-time-end" value="23:59" step="1">
                    </div>
                </div>

                {{-- Recording type --}}
                <div class="pb-field">
                    <label class="pb-label">{{ trans('front.recording_type') }}</label>
                    <select class="pb-select" id="pb-file-type">
                        <option value="1">{{ trans('front.recording_normal') }}</option>
                        <option value="2">{{ trans('front.recording_alarm') }}</option>
                    </select>
                </div>

                {{-- Recording location --}}
                <div class="pb-field">
                    <label class="pb-label">{{ trans('front.recording_location') }}</label>
                    <select class="pb-select" id="pb-storage">
                        <option value="sd1">{{ trans('front.recording_device_sd') }}</option>
                        <option value="hd1">HDD</option>
                        <option value="sd1;hd1">{{ trans('front.recording_device_sd') }} + HDD</option>
                    </select>
                </div>

                {{-- Stream --}}
                <div class="pb-field">
                    <label class="pb-label">{{ trans('front.stream_type') }}</label>
                    <select class="pb-select" id="pb-stream">
                        <option value="1">{{ trans('front.stream_main') }}</option>
                        <option value="0">{{ trans('front.stream_sub') }}</option>
                    </select>
                </div>

                <button class="pb-btn-search" id="pb-btn-search">
                    {{ trans('front.search') }}
                </button>

            </div>
        </aside>

        {{-- ── MAIN ─────────────────────────────────────────────────── --}}
        <main class="pb-main">

            {{-- Toolbar --}}
            <div class="pb-toolbar">
                <button class="pb-tool-btn" id="tb-fullscreen"
                    title="{{ trans('front.fullscreen') ?? 'Fullscreen' }}">
                    <i class="fa fa-expand"></i>
                </button>
                <div class="pb-tool-sep"></div>
                <button class="pb-tool-btn active" id="tb-quad" title="4 channels">
                    <i class="fa fa-th-large"></i>
                </button>
                <button class="pb-tool-btn" id="tb-single" title="1 channel">
                    <i class="fa fa-square-o"></i>
                </button>
                <div class="pb-tool-sep"></div>
                {{-- Stop all --}}
                <button class="pb-tool-btn" id="tb-stop-all" title="{{ trans('front.video_stop') }}">
                    <i class="fa fa-stop"></i> {{ trans('front.video_stop') }}
                </button>
                <span id="pb-status" class="pb-status-bar"></span>
            </div>

            {{-- 2×2 Video Grid --}}
            <div class="pb-grid" id="pb-grid">
                @for ($ch = 1; $ch <= 4; $ch++)
                    <div class="pb-panel {{ $ch === 1 ? 'active' : '' }}" id="panel-{{ $ch }}">
                        <div class="pb-panel-title" id="panel-title-{{ $ch }}">
                            {{ trans('front.channel') }} {{ $ch }}
                        </div>
                        <video id="video-{{ $ch }}" playsinline muted controls></video>
                        <div class="pb-panel-overlay" id="overlay-{{ $ch }}">
                            <div class="pb-panel-play" id="play-btn-{{ $ch }}"
                                data-channel="{{ $ch }}">
                                <i class="fa fa-play"></i>
                            </div>
                            <span style="margin-top:8px; font-size:.7rem; color:#444">
                                CH{{ $ch }}
                            </span>
                        </div>
                    </div>
                @endfor
            </div>

            {{-- Bottom panel --}}
            <div class="pb-bottom">

                <div class="pb-tabs-bar">
                    <div class="pb-tab active" data-tab="timeline">{{ trans('front.timeline') }}</div>
                    <div class="pb-tab" data-tab="files">{{ trans('front.file_list') }}</div>
                    <div class="pb-tab" data-tab="track">{{ trans('front.track') }}</div>
                    <div class="pb-tab" data-tab="trend">{{ trans('front.trend') }}</div>
                </div>

                {{-- Timeline --}}
                <div class="pb-tab-pane active" id="pane-timeline">
                    <div class="pb-tl-wrap">
                        <div class="pb-tl-toolbar">
                            <button class="pb-tl-btn" id="tl-btn-download" disabled>
                                <i class="fa fa-download"></i> {{ trans('front.download') }}
                            </button>
                            <label
                                style="display:flex;align-items:center;gap:4px;font-size:.73rem;color:#666;cursor:pointer">
                                <input type="checkbox" id="tl-chk-all">
                                {{ trans('front.select_all') }}
                            </label>
                        </div>
                        <div class="pb-tl-chart" id="pb-tl-chart">
                            {{-- Rendered by JS --}}
                            <div
                                style="display:flex;align-items:center;justify-content:center;height:100%;color:#444;font-size:.78rem">
                                {{ trans('front.no_files_found') }}
                            </div>
                        </div>
                    </div>
                </div>

                {{-- File list --}}
                <div class="pb-tab-pane" id="pane-files">
                    <div class="pb-file-scroll">
                        <table class="pb-file-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="fl-chk-all"></th>
                                    <th>{{ trans('front.channel') }}</th>
                                    <th>{{ trans('front.pb_start_time') }}</th>
                                    <th>{{ trans('front.pb_end_time') }}</th>
                                    <th>{{ trans('front.pb_duration') }}</th>
                                    <th>{{ trans('front.file_size') }}</th>
                                    <th>{{ trans('front.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody id="pb-file-body">
                                <tr>
                                    <td colspan="7" style="text-align:center;color:#444;padding:18px">
                                        {{ trans('front.no_files_found') }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Track --}}
                <div class="pb-tab-pane" id="pane-track">
                    <div class="pb-tab-placeholder">
                        <i class="fa fa-map-marker"></i> {{ trans('front.track') }}
                    </div>
                </div>

                {{-- Trend --}}
                <div class="pb-tab-pane" id="pane-trend">
                    <div class="pb-tab-placeholder">
                        <i class="fa fa-line-chart"></i> {{ trans('front.trend') }}
                    </div>
                </div>

            </div>{{-- .pb-bottom --}}

        </main>

    </div>{{-- .pb-wrap --}}

    @include('Frontend.Layouts.partials.app')
    @include('Frontend.Layouts.partials.trans')
    <script src="{{ asset_resource('assets/js/core.js') }}"></script>
    <script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.7/dist/hls.min.js"></script>

    <script>
        (function() {
            'use strict';

            /* ── Translations ─────────────────────────────────────────── */
            var T = {
                noFilesFound: @json(trans('front.no_files_found')),
                searching: @json(trans('front.pb_searching')),
                channel: @json(trans('front.channel')),
                pleaseSelect: @json(trans('front.please_select')),
                streamError: 'Stream error',
            };

            /* ── Channel colors ──────────────────────────────────────── */
            var CH_COLORS = {
                1: '#2196f3',
                2: '#4caf50',
                3: '#ff9800',
                4: '#e91e63'
            };

            /* ── State ───────────────────────────────────────────────── */
            var deviceId = null;
            var selectedDate = new Date();
            var calView = {
                year: selectedDate.getFullYear(),
                month: selectedDate.getMonth()
            };
            var searchSession = null;
            var pollTimer = null;
            var foundFiles = [];
            var hlsPlayers = {
                1: null,
                2: null,
                3: null,
                4: null
            };
            var singleView = false;
            var activePanel = 1;

            /* ── DOM helpers ─────────────────────────────────────────── */
            var $ = function(id) {
                return document.getElementById(id);
            };

            var elDevice = $('pb-device');
            var elTimeStart = $('pb-time-start');
            var elTimeEnd = $('pb-time-end');
            var elFileType = $('pb-file-type');
            var elStorage = $('pb-storage');
            var elStream = $('pb-stream');
            var elBtnSearch = $('pb-btn-search');
            var elStatus = $('pb-status');
            var elFileBody = $('pb-file-body');
            var elGrid = $('pb-grid');
            var elTlChart = $('pb-tl-chart');

            /* ═════════════════════════════════════════════════════════════
               CALENDAR
            ═════════════════════════════════════════════════════════════ */
            var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'
            ];
            var WEEKDAYS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

            function renderCalendar() {
                var y = calView.year,
                    m = calView.month;
                $('cal-title').textContent = MONTHS[m] + '  ' + y;

                var wdEl = $('cal-weekdays');
                wdEl.innerHTML = '';
                WEEKDAYS.forEach(function(d) {
                    var s = document.createElement('span');
                    s.textContent = d;
                    wdEl.appendChild(s);
                });

                var daysEl = $('cal-days');
                daysEl.innerHTML = '';
                var firstDay = new Date(y, m, 1).getDay();
                var daysInMonth = new Date(y, m + 1, 0).getDate();
                var today = new Date();

                /* Empty cells before first weekday */
                for (var i = 0; i < firstDay; i++) {
                    var empty = document.createElement('div');
                    empty.className = 'pb-cal-day empty';
                    daysEl.appendChild(empty);
                }

                for (var d = 1; d <= daysInMonth; d++) {
                    (function(day) {
                        var cell = document.createElement('div');
                        cell.className = 'pb-cal-day';
                        cell.textContent = day;
                        if (today.getFullYear() === y && today.getMonth() === m && today.getDate() === day) {
                            cell.classList.add('today');
                        }
                        if (selectedDate.getFullYear() === y && selectedDate.getMonth() === m && selectedDate
                            .getDate() === day) {
                            cell.classList.add('selected');
                        }
                        cell.addEventListener('click', function() {
                            selectedDate = new Date(y, m, day);
                            renderCalendar();
                        });
                        daysEl.appendChild(cell);
                    })(d);
                }
            }

            $('cal-prev-yr').addEventListener('click', function() {
                calView.year--;
                renderCalendar();
            });
            $('cal-next-yr').addEventListener('click', function() {
                calView.year++;
                renderCalendar();
            });
            $('cal-prev-mo').addEventListener('click', function() {
                if (--calView.month < 0) {
                    calView.month = 11;
                    calView.year--;
                }
                renderCalendar();
            });
            $('cal-next-mo').addEventListener('click', function() {
                if (++calView.month > 11) {
                    calView.month = 0;
                    calView.year++;
                }
                renderCalendar();
            });

            /* ═════════════════════════════════════════════════════════════
               TABS
            ═════════════════════════════════════════════════════════════ */
            document.querySelectorAll('.pb-tab').forEach(function(tab) {
                tab.addEventListener('click', function() {
                    document.querySelectorAll('.pb-tab').forEach(function(t) {
                        t.classList.remove('active');
                    });
                    document.querySelectorAll('.pb-tab-pane').forEach(function(p) {
                        p.classList.remove('active');
                    });
                    tab.classList.add('active');
                    $('pane-' + tab.dataset.tab).classList.add('active');
                });
            });

            /* ═════════════════════════════════════════════════════════════
               TOOLBAR
            ═════════════════════════════════════════════════════════════ */
            $('tb-quad').addEventListener('click', function() {
                singleView = false;
                elGrid.classList.remove('single-view');
                $('tb-quad').classList.add('active');
                $('tb-single').classList.remove('active');
            });
            $('tb-single').addEventListener('click', function() {
                singleView = true;
                /* show activePanel, hide others */
                for (var c = 1; c <= 4; c++) {
                    var p = $('panel-' + c);
                    if (p) p.classList.toggle('active', c === activePanel);
                }
                elGrid.classList.add('single-view');
                $('tb-single').classList.add('active');
                $('tb-quad').classList.remove('active');
            });
            $('tb-fullscreen').addEventListener('click', function() {
                if (!document.fullscreenElement) {
                    document.documentElement.requestFullscreen().catch(function() {});
                } else {
                    document.exitFullscreen().catch(function() {});
                }
            });
            $('tb-stop-all').addEventListener('click', function() {
                for (var c = 1; c <= 4; c++) {
                    destroyHls(c);
                }
                setStatus('', '');
            });

            /* Double-click panel → set as active in single view */
            for (var _c = 1; _c <= 4; _c++) {
                (function(ch) {
                    var panel = $('panel-' + ch);
                    if (panel) {
                        panel.addEventListener('dblclick', function() {
                            activePanel = ch;
                            if (singleView) {
                                for (var c = 1; c <= 4; c++) {
                                    $('panel-' + c).classList.toggle('active', c === ch);
                                }
                            }
                        });
                    }
                })(_c);
            }

            /* ═════════════════════════════════════════════════════════════
               HELPERS
            ═════════════════════════════════════════════════════════════ */
            function padTwo(n) {
                return n < 10 ? '0' + n : '' + n;
            }

            function dateStr(d) {
                return d.getFullYear() + '-' + padTwo(d.getMonth() + 1) + '-' + padTwo(d.getDate());
            }

            function csrfToken() {
                var m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
                return m ? decodeURIComponent(m[1]) : (
                    document.querySelector('meta[name="csrf-token"]') ?
                    document.querySelector('meta[name="csrf-token"]').getAttribute('content') :
                    ''
                );
            }

            function postJson(url, data) {
                return fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': csrfToken(),
                        'X-CSRF-TOKEN': csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(data),
                }).then(function(r) {
                    return r.json();
                });
            }

            function getJson(url) {
                return fetch(url, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                }).then(function(r) {
                    return r.json();
                });
            }

            function setStatus(msg, cls) {
                elStatus.textContent = msg;
                elStatus.className = 'pb-status-bar ' + (cls || '');
            }

            function secsToDuration(secs) {
                if (!secs || secs < 0) return '--';
                var h = Math.floor(secs / 3600);
                var m = Math.floor((secs % 3600) / 60);
                var s = Math.floor(secs % 60);
                return padTwo(h) + ':' + padTwo(m) + ':' + padTwo(s);
            }

            function fmtSize(bytes) {
                if (!bytes) return '--';
                if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB';
                if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
                return (bytes / 1024).toFixed(0) + ' KB';
            }

            function fileDuration(f) {
                if (f.duration) return secsToDuration(f.duration);
                if (f.startTime && f.endTime) {
                    var ms = (new Date(f.endTime) - new Date(f.startTime)) / 1000;
                    return secsToDuration(Math.max(0, ms));
                }
                return '--';
            }

            /* ═════════════════════════════════════════════════════════════
               SEARCH
            ═════════════════════════════════════════════════════════════ */
            elBtnSearch.addEventListener('click', function() {
                var devId = parseInt(elDevice.value, 10);
                if (!devId) {
                    setStatus(T.pleaseSelect, 'error');
                    return;
                }
                deviceId = devId;
                doSearch();
            });

            function doSearch() {
                if (pollTimer) {
                    clearInterval(pollTimer);
                    pollTimer = null;
                }
                foundFiles = [];
                renderFileBody([]);
                renderTimeline([]);

                var dateVal = dateStr(selectedDate);
                var tsStart = elTimeStart.value || '00:00';
                var tsEnd = elTimeEnd.value || '23:59';
                /* Ensure seconds part */
                if (tsStart.length === 5) tsStart += ':00';
                if (tsEnd.length === 5) tsEnd += ':59';

                setStatus(T.searching, 'info');
                elBtnSearch.disabled = true;

                postJson('/device_video/' + deviceId + '/files/search', {
                    channels: '1;2;3;4',
                    startTime: dateVal + ' ' + tsStart,
                    endTime: dateVal + ' ' + tsEnd,
                    fileType: elFileType.value,
                    storage: elStorage.value,
                    stream: elStream.value,
                }).then(function(res) {
                    if (res && res.session) {
                        searchSession = res.session;
                        pollTimer = setInterval(function() {
                            pollFiles(searchSession);
                        }, 2000);
                    } else {
                        setStatus('Error: ' + (res && res.message ? res.message : 'no session'), 'error');
                        elBtnSearch.disabled = false;
                    }
                }).catch(function(e) {
                    setStatus('Error: ' + e.message, 'error');
                    elBtnSearch.disabled = false;
                });
            }

            function pollFiles(session) {
                getJson('/device_video/' + deviceId + '/files/' + session)
                    .then(function(res) {
                        if (!res) return;

                        if (Array.isArray(res.files) && res.files.length > 0) {
                            foundFiles = foundFiles.concat(res.files);
                            renderFileBody(foundFiles);
                            renderTimeline(foundFiles);
                        }

                        /* Poll termination: server signals done */
                        var isDone = res.done === true || res.finished === true ||
                            res.complete === true ||
                            (Array.isArray(res.files) && res.files.length === 0);

                        if (isDone) {
                            clearInterval(pollTimer);
                            pollTimer = null;
                            elBtnSearch.disabled = false;
                            setStatus(
                                foundFiles.length > 0 ?
                                '✓ ' + foundFiles.length + ' file(s)' :
                                T.noFilesFound,
                                foundFiles.length > 0 ? 'ok' : ''
                            );
                        }
                    })
                    .catch(function() {
                        clearInterval(pollTimer);
                        pollTimer = null;
                        elBtnSearch.disabled = false;
                    });
            }

            /* ═════════════════════════════════════════════════════════════
               FILE LIST
            ═════════════════════════════════════════════════════════════ */
            function renderFileBody(files) {
                if (!files || files.length === 0) {
                    elFileBody.innerHTML =
                        '<tr><td colspan="7" style="text-align:center;color:#444;padding:18px">' +
                        T.noFilesFound + '</td></tr>';
                    return;
                }

                var html = '';
                files.forEach(function(f, idx) {
                    var ch = f.channel || f.ch || '1';
                    html += '<tr data-idx="' + idx + '">';
                    html += '<td><input type="checkbox" class="fl-chk"></td>';
                    html += '<td>' + T.channel + ' ' + ch + '</td>';
                    html += '<td>' + (f.startTime || f.start || '--') + '</td>';
                    html += '<td>' + (f.endTime || f.end || '--') + '</td>';
                    html += '<td>' + fileDuration(f) + '</td>';
                    html += '<td>' + fmtSize(f.size || f.fileSize || 0) + '</td>';
                    html += '<td><button class="pb-file-btn" onclick="pbPlayFile(' + idx + ')">▶</button></td>';
                    html += '</tr>';
                });

                elFileBody.innerHTML = html;

                /* Select-all checkbox */
                $('fl-chk-all').onchange = function() {
                    document.querySelectorAll('.fl-chk').forEach(function(c) {
                        c.checked = this.checked;
                    }, this);
                };
            }

            /* ═════════════════════════════════════════════════════════════
               PLAYBACK
            ═════════════════════════════════════════════════════════════ */
            window.pbPlayFile = function(idx) {
                var file = foundFiles[idx];
                if (!file || !deviceId) return;

                var channel = parseInt(file.channel || file.ch || 1, 10);
                if (channel < 1 || channel > 4) channel = 1;

                /* Highlight row */
                document.querySelectorAll('#pb-file-body tr').forEach(function(tr) {
                    tr.classList.toggle('selected', parseInt(tr.dataset.idx, 10) === idx);
                });

                setStatus('Starting playback ch' + channel + '…', 'info');

                postJson('/device_video/' + deviceId + '/playback/start', {
                    channels: String(channel),
                    startTime: file.startTime || file.start || null,
                    endTime: file.endTime || file.end || null,
                    fileName: file.fileName || file.name || null,
                    stream: elStream.value,
                }).then(function(res) {
                    if (res && res.hls_url) {
                        loadHls(channel, res.hls_url);
                    } else if (res && res.session) {
                        /* Build proxy URL through our stream endpoint */
                        loadHls(channel, '/device_video/' + deviceId + '/stream/playback.m3u8?session=' +
                            res.session);
                    } else {
                        setStatus('Playback error: ' + (res && res.message ? res.message : 'no URL'),
                            'error');
                    }
                }).catch(function(e) {
                    setStatus('Error: ' + e.message, 'error');
                });
            };

            /* ═════════════════════════════════════════════════════════════
               HLS PLAYERS
            ═════════════════════════════════════════════════════════════ */
            function destroyHls(ch) {
                if (hlsPlayers[ch]) {
                    hlsPlayers[ch].destroy();
                    hlsPlayers[ch] = null;
                }
                var v = $('video-' + ch);
                if (v) v.src = '';
                var ov = $('overlay-' + ch);
                if (ov) {
                    ov.innerHTML =
                        '<div class="pb-panel-play" id="play-btn-' + ch + '" data-channel="' + ch + '">' +
                        '<i class="fa fa-play"></i></div>' +
                        '<span style="margin-top:8px;font-size:.7rem;color:#444">CH' + ch + '</span>';
                    ov.classList.remove('hidden');
                    ov.querySelector('.pb-panel-play').addEventListener('click', function() {
                        /* click on play button in quad → switch active channel */
                        activePanel = ch;
                    });
                }
                var title = $('panel-title-' + ch);
                if (title) title.className = 'pb-panel-title';
            }

            function loadHls(ch, url) {
                destroyHls(ch);
                var video = $('video-' + ch);
                var overlay = $('overlay-' + ch);
                var title = $('panel-title-' + ch);
                if (!video) return;

                overlay.innerHTML =
                    '<div class="pb-spinner"></div>' +
                    '<span style="font-size:.72rem;color:#777">Loading…</span>';
                overlay.classList.remove('hidden');

                function onReady() {
                    overlay.classList.add('hidden');
                    if (title) title.className = 'pb-panel-title live-badge';
                    setStatus('Playing ch' + ch, 'ok');
                    video.play().catch(function() {});
                }

                function onFatal(detail) {
                    overlay.innerHTML =
                        '<i class="fa fa-exclamation-triangle" style="color:#ef5350;font-size:1.6rem"></i>' +
                        '<span style="color:#ef5350;font-size:.73rem;margin-top:6px">' + T.streamError + '</span>';
                    overlay.classList.remove('hidden');
                    if (title) title.className = 'pb-panel-title';
                    setStatus('Error ch' + ch + ': ' + (detail || ''), 'error');
                }

                if (window.Hls && Hls.isSupported()) {
                    var h = new Hls({
                        enableWorker: false,
                        maxBufferLength: 30
                    });
                    hlsPlayers[ch] = h;
                    h.loadSource(url);
                    h.attachMedia(video);
                    h.on(Hls.Events.MANIFEST_PARSED, onReady);
                    h.on(Hls.Events.ERROR, function(evt, data) {
                        if (data.fatal) onFatal(data.type);
                    });
                } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                    video.src = url;
                    video.addEventListener('loadedmetadata', onReady, {
                        once: true
                    });
                    video.addEventListener('error', function() {
                        onFatal('media error');
                    }, {
                        once: true
                    });
                } else {
                    overlay.innerHTML = '<span style="color:#ef5350;font-size:.8rem">HLS not supported</span>';
                }
            }

            /* ═════════════════════════════════════════════════════════════
               TIMELINE
            ═════════════════════════════════════════════════════════════ */
            function renderTimeline(files) {
                if (!elTlChart) return;

                if (!files || files.length === 0) {
                    elTlChart.innerHTML =
                        '<div style="display:flex;align-items:center;justify-content:center;' +
                        'height:100%;color:#444;font-size:.78rem">' + T.noFilesFound + '</div>';
                    return;
                }

                var dayStartMs = new Date(dateStr(selectedDate) + 'T00:00:00').getTime();
                var totalMs = 86400000; /* 24 h */

                /* Group files by channel */
                var byChannel = {};
                files.forEach(function(f) {
                    var ch = parseInt(f.channel || f.ch || 1, 10);
                    if (!byChannel[ch]) byChannel[ch] = [];
                    byChannel[ch].push(f);
                });

                var channels = Object.keys(byChannel).sort();

                /* Build hour axis */
                var axisHtml = '<div class="pb-tl-axis">';
                for (var h = 0; h <= 24; h += 2) {
                    var pct = (h / 24) * 100;
                    axisHtml += '<span class="pb-tl-hour" style="left:' + pct + '%">' + padTwo(h) + ':00</span>';
                }
                axisHtml += '</div>';

                /* Build one row per channel */
                var rowsHtml = '<div class="pb-tl-rows">';
                channels.forEach(function(ch) {
                    var color = CH_COLORS[ch] || '#777';
                    var segsHtml = '';
                    byChannel[ch].forEach(function(f, fi) {
                        var st = new Date(f.startTime || f.start || 0).getTime();
                        var et = new Date(f.endTime || f.end || 0).getTime();
                        if (!st || !et || et <= st) return;

                        var leftPct = Math.max(0, ((st - dayStartMs) / totalMs) * 100);
                        var rightPct = Math.min(100, ((et - dayStartMs) / totalMs) * 100);
                        var widthPct = Math.max(0.15, rightPct - leftPct);
                        var fileIdx = foundFiles.indexOf(f);

                        segsHtml +=
                            '<div class="pb-tl-seg" ' +
                            'style="left:' + leftPct + '%;width:' + widthPct + '%;background:' + color +
                            ';" ' +
                            'title="CH' + ch + '  ' + (f.startTime || '') + ' – ' + (f.endTime || '') +
                            '" ' +
                            'onclick="pbPlayFile(' + fileIdx + ')">' +
                            '</div>';
                    });

                    rowsHtml +=
                        '<div class="pb-tl-ch-row">' +
                        '<span class="pb-tl-ch-lbl">CH' + ch + '</span>' +
                        '<div class="pb-tl-ch-bar">' + segsHtml + '</div>' +
                        '</div>';
                });
                rowsHtml += '</div>';

                elTlChart.innerHTML = axisHtml + rowsHtml;
            }

            /* ═════════════════════════════════════════════════════════════
               LEFT NAV — keep links on-page
            ═════════════════════════════════════════════════════════════ */
            document.querySelectorAll('.pb-nav-item').forEach(function(el) {
                el.addEventListener('click', function(e) {
                    e.preventDefault();
                });
            });

            /* ═════════════════════════════════════════════════════════════
               INIT
            ═════════════════════════════════════════════════════════════ */
            renderCalendar();
            renderTimeline([]);

        })();
    </script>

</body>

</html>
