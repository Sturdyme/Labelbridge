
<?php

use App\Http\Controllers\Api\AdminLabelController;
use App\Http\Controllers\Api\ApplicationDashboardController;
use App\Http\Controllers\Api\ArtistProfileController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\LabelApplicationController;
use App\Http\Controllers\Api\LabelCertificationController;
use App\Http\Controllers\Api\LabelDiscoveryController;
use App\Http\Controllers\Api\LabelInvitationController;
use App\Http\Controllers\Api\LabelProfileController;
use App\Http\Controllers\Api\PointPurchaseController;
use App\Http\Controllers\Api\PublicProfileController;
use App\Http\Controllers\Api\TrackController;
use App\Http\Controllers\Api\TrackDiscoveryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Authentication Routes
|--------------------------------------------------------------------------
*/

Route::post('/register', [
    AuthController::class,
    'register',
])->middleware('throttle:5,1');

Route::post('/login', [
    AuthController::class,
    'login',
])->middleware('throttle:5,1');

/*
|--------------------------------------------------------------------------
| Authenticated User Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [
        AuthController::class,
        'me',
    ]);

    Route::post('/logout', [
        AuthController::class,
        'logout',
    ]);
});

/*
|--------------------------------------------------------------------------
| Email Verification Routes
|--------------------------------------------------------------------------
*/

// Verify an email using a signed link.
Route::get('/email/verify/{id}/{hash}', [
    EmailVerificationController::class,
    'verify',
])->middleware([
    'signed',
    'throttle:6,1',
])->name('verification.verify');

// Resend the verification email.
Route::post('/email/verification-notification', [
    EmailVerificationController::class,
    'resend',
])->middleware([
    'auth:sanctum',
    'throttle:6,1',
])->name('verification.send');

/*
|--------------------------------------------------------------------------
| Approved Label Routes
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth:sanctum',
    'email.verified',
    'label.approved',
])->prefix('label')->group(function () {
    Route::get(
        '/dashboard',
        [ApplicationDashboardController::class, 'labelDashboard']
    );

    Route::patch(
        '/applications/{application}/status',
        [ApplicationDashboardController::class, 'reviewApplication']
    );
});

/*
|--------------------------------------------------------------------------
| Administrator Routes
|--------------------------------------------------------------------------
*/
Route::middleware([
    'auth:sanctum',
    'email.verified',
    'admin',
])->prefix('admin')->group(function () {
    Route::get('/labels', [AdminLabelController::class, 'index']);

    Route::patch(
        '/labels/{user}/status',
        [AdminLabelController::class, 'updateStatus']
    );

    

    Route::patch('/labels/{user}/certification/revoke', [AdminLabelController::class, 'revokeCertification']);
});

// Artist Profile routes

Route::middleware(['auth:sanctum', 'email.verified'])->prefix('artist/profile')->group(function () {
    Route::get('/', [ArtistProfileController::class, 'show']);
    Route::put('/', [ArtistProfileController::class, 'save']);
});

Route::middleware(['auth:sanctum', 'email.verified'])->prefix('label/profile')->group(function () {
    Route::get('/', [LabelProfileController::class, 'show']);
    Route::put('/', [LabelProfileController::class, 'save']);
});

Route::middleware([
    'auth:sanctum',
    'email.verified',
])->prefix('artist')->group(function () {
    Route::get(
        '/dashboard',
        [ApplicationDashboardController::class, 'artistDashboard']
    );
});

// Public profile routes

Route::middleware(['auth:sanctum', 'email.verified'])->prefix('public')->group(function () {
    Route::get('/artists/{artistProfile}', [PublicProfileController::class, 'showArtist']);

    Route::get('/labels/{labelProfile}', [PublicProfileController::class, 'showLabel']);
});

// Artists tracks upload route

Route::middleware(['auth:sanctum', 'email.verified'])->prefix('artist')->group(function () {
    Route::get('/tracks', [TrackController::class, 'index']);
    Route::post('/tracks', [TrackController::class, 'store']);
    Route::patch('/tracks/{track}/publish', [TrackController::class, 'publish']);

});

Route::middleware(['auth:sanctum', 'email.verified'])->get('/tracks/{track}/stream', [TrackController::class, 'stream']);

Route::middleware(['auth:sanctum', 'email.verified'])->get('/discovery/tracks', [TrackDiscoveryController::class, 'index']);

// Label dicovery routes

Route::middleware(['auth:sanctum', 'email.verified'])->get('/discovery/labels', [LabelDiscoveryController::class, 'index']);

// Artist Application to Labels routes
Route::middleware(['auth:sanctum', 'email.verified'])->post('/labels/{labelProfile}/applications', [LabelApplicationController::class, 'store']);

// Label invitations to artist route
Route::middleware(['auth:sanctum', 'email.verified'])->post('/label/invitations', [LabelInvitationController::class, 'invite']);

Route::middleware(['auth:sanctum', 'email.verified'])->group(function () {
    Route::post('/artist/invitations/{invitation}/accept', [LabelInvitationController::class, 'accept']);

    Route::post('/artist/invitations/{invitation}/decline', [LabelInvitationController::class, 'decline']);
});

// Points purchase route

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/points/purchase', [PointPurchaseController::class, 'initialize']);
    Route::post('/points/verify', [PointPurchaseController::class, 'verify']);

});

// Annual label certification payment routes

Route::middleware(['auth:sanctum', 'email.verified'])->group(function () {
    Route::post('/label/certification/purchase', [LabelCertificationController::class, 'initialize']);

    Route::post('/label/certification/verify', [LabelCertificationController::class, 'verify']);

});

Route::get('/diagnostic-status', function () {
    return response()->json([
        'message' => 'Explicit status test',
    ], 418);
});

Route::get('/diagnostic-headers', function () {
    $file = null;
    $line = null;

    $sent = headers_sent($file, $line);

    return response()->json([
        'headers_sent_before_response' => $sent,
        'output_file' => $file,
        'output_line' => $line,
        'headers_before_response' => headers_list(),
        'output_buffers' => ob_get_status(true),
    ]);
});