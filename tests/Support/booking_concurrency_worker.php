<?php

use App\Exceptions\BookingUnavailableException;
use App\Models\User;
use App\Services\BookingService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;

$projectRoot = dirname(__DIR__, 2);
require $projectRoot.'/vendor/autoload.php';
$app = require $projectRoot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $gate, $ready, $started, $userId, $petId, $doctorId, $scheduleId, $start, $end] = $argv;
file_put_contents($ready, 'ready');

$deadline = microtime(true) + 20;
while (!file_exists($gate) && microtime(true) < $deadline) {
    usleep(10_000);
}

if (!file_exists($gate)) {
    fwrite(STDERR, 'Concurrency gate was not released.');
    exit(2);
}

file_put_contents($started, 'started');
Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 12:00:00', 'Asia/Jakarta'));
CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-04 12:00:00', 'Asia/Jakarta'));

try {
    $booking = app(BookingService::class)->create(
        User::query()->findOrFail((int) $userId),
        (int) $petId,
        (int) $doctorId,
        (int) $scheduleId,
        $start,
        $end,
    );

    echo json_encode(['outcome' => 'created', 'booking_id' => $booking->getKey()], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (BookingUnavailableException) {
    echo json_encode(['outcome' => 'unavailable'], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(1);
}
