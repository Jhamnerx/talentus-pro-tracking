<style>
    .db-card {
        background: #fff;
        border: 1px solid #e4e8ec;
        border-radius: 4px;
        margin-bottom: 16px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, .06);
        overflow: hidden;
    }

    .db-card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 14px 9px;
        border-bottom: 1px solid #eef0f2;
        background: #fafbfc;
    }

    .db-card-header .db-card-title {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: #5a6878;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .db-card-header .db-card-title a {
        color: #5a6878;
        text-decoration: none;
    }

    .db-card-header .db-card-opts .btn.icon {
        color: #aab3bc;
        padding: 2px 6px;
        font-size: 13px;
    }

    .db-card-body {
        padding: 12px 14px;
        position: relative;
        min-height: 40px;
    }

    .db-card-loader {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 30px 0;
        color: #aab3bc;
        font-size: 12px;
    }
</style>

<div class="dashboard-block @yield('width', 'col-sm-6 col-md-4 col-lg-3')" id="{{ "block_$name" }}">
    <div class="db-card">
        <div class="db-card-header">
            <div class="db-card-title">
                @yield('header')
            </div>
            <div class="db-card-opts">
                @if (View::exists("Frontend.Dashboard.Blocks.$name.options"))
                    <div class="btn-group droparrow" data-position="fixed">
                        <i class="btn icon options" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"></i>
                        <div class="dropdown-menu dropdown-menu-right">
                            @include("Frontend.Dashboard.Blocks.$name.options", [
                                'options' => $config['options'],
                                'block' => $name,
                            ])
                        </div>
                    </div>
                @endif
            </div>
        </div>
        <div class="db-card-body dashboard-content">
            @yield('body')
        </div>
    </div>
    @yield('scripts')
</div>
