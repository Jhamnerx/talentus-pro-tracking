# GPS Tracking System - AI Development Guide

## Architecture Overview

This is a **Laravel 8 GPS tracking platform** built on top of a custom domain-driven architecture with a dual namespace structure:

- `App\` - Standard Laravel application layer (Controllers, Middleware, Events, Jobs)
- `Tobuli\` - Core business domain (Entities, Repositories, Services, Validation)

### Key Components

- **Entities**: Located in `Tobuli/Entities/`, these are Eloquent models with extensive traits (e.g., `Device`, `User`, `Geofence`)
- **Repositories**: Repository pattern via `Tobuli/Repositories/` with interface bindings in `RepositoriesServiceProvider`
- **Services**: Business logic in `Tobuli/Services/` (e.g., `DeviceImageService`, `EventWriteService`)
- **ModalHelpers**: Request handlers in root `ModalHelpers/` directory (e.g., `DeviceModalHelper`, `AlertModalHelper`)
- **Transformers**: API response formatting in `app/Transformers/` using Fractal library
- **Facades**: Custom facades in root `Facades/` directory accessed via `CustomFacades\` namespace

## Critical Patterns

### 1. Custom Facades (NOT Laravel's standard facade pattern)

Access repositories and helpers through custom facades:

```php
use CustomFacades\Repositories\DeviceRepo;
use CustomFacades\ModalHelpers\DeviceModalHelper;
use CustomFacades\Validators\DeviceImageValidator;
```

W
These are registered in `config/app.php` under the 'aliases' section with the `CustomFacades\` prefix.

### 2. ModalHelpers for Request Processing

ModalHelpers are central to form/request handling, NOT standard Laravel FormRequests:

- Extend `ModalHelper` base class
- Handle permission checks via `checkException($repo, $action, $model)`
- Set context with `setUser()`, `setData()`, `setApi()`
- Example: `DeviceModalHelper::setData($request->all())` before processing

### 3. Repository Dependency Injection

Repositories are bound in `Tobuli/Repositories/RepositoriesServiceProvider.php`:

```php
$this->app->bind('Tobuli\Repositories\Device\DeviceRepositoryInterface',
                 'Tobuli\Repositories\Device\EloquentDeviceRepository');
```

Access via interfaces or custom facades, never instantiate directly.

### 4. Entity Traits System

Entities use multiple traits for cross-cutting concerns:

- `Filterable`, `Searchable`, `Nameable` - Query helpers
- `Customizable`, `Chattable` - Feature extensions
- `EventLoggable`, `ChangeLogs` - Audit trail
- `FcmTokensTrait` - Push notifications

Always check entity traits before adding duplicate functionality.

### 5. Route Structure

- `routes/web.php` - Main frontend routes (1000+ lines, grouped by middleware)
- `routes/api.php` - API v1 routes with `auth.api` middleware
- `routes/app.php` - Additional application routes

Controllers are namespaced under `Frontend`, `Admin`, or `Api`.

## Development Workflows

### Running the Application

```bash
# Start Laravel development server
php artisan serve

# Start socket server (required for real-time updates)
cd socket
node socket.js
```

### Asset Compilation

Uses Gulp (not Laravel Mix):

```bash
# Install frontend dependencies
npm install

# Compile assets (SCSS, JS concatenation)
gulp
gulp watch  # For development
```

Asset configuration in `gulpfile.js` defines bundles like 'core', 'report', 'admin'.

### Database & Migrations

```bash
php artisan migrate
php artisan db:seed

# Device-specific commands
php artisan devices:update-config
```

### Real-time Communication

- Redis + Socket.io for real-time GPS updates
- Socket server reads from Redis pub/sub at `socket/socket.js`
- Channels use pattern `*` subscription with `pmessage` events
- Configuration via `.env`: `SOCKET_PORT`, `SOCKET_SSL_PORT`, `REDIS_HOST`, `REDIS_PORT`

## Common Gotchas

### Permission Checking

Always use ModalHelper's `checkException()` or manual checks:

```php
if (!$this->user->perm('devices', 'view'))
    throw new PermissionException();
```

### API vs Web Context

Many classes check `$this->api` flag to alter behavior. Set via:

```php
Config::set('tobuli.api', 1);  // Or via ModalHelper::setApi(true)
```

### Transformers for API Responses

Use FractalTransformer service, not direct array returns:

```php
use FractalTransformer;
return FractalTransformer::item($device, new DeviceMapTransformer());
```

### Custom Middleware

Middleware in `app/Http/Kernel.php` includes:

- `active_subscription` - Required for most authenticated routes
- `auth.tracker` - GPS tracker authentication
- `confirmed_action` - Two-step confirmation
- `verified` - Email verification via `EnsureEmailIsVerified`

### Device-Specific Logic

The `Device` entity is central (2000+ lines). Key relationships:

- `user()` - Owner
- `sensors()` - Sensor configurations
- `alerts()` - Alert rules
- `traccarDevice()` - Integration with Traccar GPSServer

## File Organization

- **Root directories** (`ModalHelpers/`, `Facades/`, `Tobuli/`) contain business logic
- **Standard Laravel** structure under `app/`, `config/`, `routes/`
- **Views** in `resources/views/` and `Tobuli/Views/`
- **Frontend assets** in `resources/assets/` (compiled to `public/assets/`)
- **Socket server** isolated in `socket/` directory

## Integration Points

- **Traccar GPSServer**: Backend tracking via `TraccarDevice` entities
- **Redis**: Caching, queues, and pub/sub for real-time updates
- **Socket.io**: WebSocket connections for live data
- **Payment Gateways**: Stripe, Braintree configurations in `config/payments.php`
- **External APIs**: SendGrid (email), Plivo (SMS), Azure (SSO), FCM (push notifications)

## Testing Approach

Tests located in `tests/Feature/` and `tests/Unit/`. Base class `TestCase.php` extends Laravel's foundation.

---

**Version**: Laravel 8.x | **Build**: 202503200000000000 | **App**: SEO GPS
