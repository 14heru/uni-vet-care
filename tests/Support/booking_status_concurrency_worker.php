<?php

use App\Exceptions\InvalidBookingStatusTransitionException;
use App\Models\Booking;
use App\Models\User;
use App\Services\BookingStatusService;
use Illuminate\Contracts\Console\Kernel;

$projectRoot = dirname(__DIR__, 2);
require $projectRoot.'/vendor/autoload.php';
$app = require $projectRoot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $gate, $ready, $started, $bookingId, $actorUserId, $newStatus] = $argv;
file_put_contents($ready, 'ready');

$deadline = microtime(true) + 20;
while (! file_exists($gate) && microtime(true) < $deadline) {
    usleep(10_000);
}

if (! file_exists($gate)) {
    fwrite(STDERR, 'Concurrency gate was not released.');
    exit(2);
}

file_put_contents($started, 'started');

try {
    $booking = Booking::query()->findOrFail((int) $bookingId);
    $actor = User::query()->findOrFail((int) $actorUserId);
    $updated = app(BookingStatusService::class)->transition($booking, $actor, $newStatus);

    echo json_encode(['outcome' => 'transitioned', 'status' => $updated->status], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (InvalidBookingStatusTransitionException) {
    echo json_encode(['outcome' => 'rejected'], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(1);
}
