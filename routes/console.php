<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\ContentReport;
use App\Services\ContentModerationService;

Artisan::command('moderation:reports', function () {
    $reports = ContentReport::query()->where('status', 'pending')->orderBy('due_at')->get();
    $this->table(['ID', 'Content', 'User', 'Reason', 'Due (UTC)', 'Alert'], $reports->map(fn ($report) => [
        $report->id, $report->content_type.':'.$report->content_id, $report->reported_user_id,
        $report->reason, $report->due_at->utc()->toDateTimeString(),
        $report->due_at->isPast() ? 'OVERDUE' : ($report->seen_at ? 'Pending' : 'NEW'),
    ])->all());
})->purpose('Show backend abuse reports and their 24-hour review deadlines');

Artisan::command('moderation:review {report} {--action=} {--reviewer=} {--note=}', function () {
    $action = $this->option('action');
    if (!in_array($action, ['remove', 'dismiss'], true) || !$this->option('reviewer') || !$this->option('note')) {
        $this->error('Supply --action=remove|dismiss, --reviewer and --note.');
        return 1;
    }
    $report = ContentReport::query()->find($this->argument('report'));
    if (!$report || $report->status !== 'pending') {
        $this->error('Pending report not found.');
        return 1;
    }
    app(ContentModerationService::class)->review($report, $action, $this->option('reviewer'), $this->option('note'));
    $this->info($action === 'remove' ? 'Content removed, offending account suspended, and sessions revoked.' : 'Report dismissed.');
    return 0;
})->purpose('Review a report; confirmed abuse removes content and ejects its author');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
