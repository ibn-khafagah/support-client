<?php

namespace IbnKhafagah\SupportClient\Laravel\View;

use IbnKhafagah\SupportClient\Laravel\ContextCollector;
use IbnKhafagah\SupportClient\Laravel\LastError;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Component;
use Throwable;

/**
 * <x-support-report-button /> for the host's errors/500.blade.php: the
 * error's reference and a "Report this problem" link to the new-ticket
 * form, which then carries the reference, the failed page and the
 * "System error" category.
 *
 * Renders nothing for a guest, when the package is not configured
 * (SUPPORT_API_URL / SUPPORT_API_KEY), or if anything goes wrong while
 * building it: an error page must never fail because of this button.
 */
class ReportButton extends Component
{
    /** The reference of the error on this page, if it was remembered during this request. */
    public ?string $ref = null;

    public string $href = '';

    private bool $visible = false;

    public function __construct()
    {
        try {
            $this->prepare();
        } catch (Throwable) {
            $this->visible = false;
        }
    }

    public function shouldRender(): bool
    {
        return $this->visible;
    }

    public function render(): View
    {
        return view('support-client::components.report-button');
    }

    private function prepare(): void
    {
        $url = config('support-client.url');
        $key = config('support-client.key');
        if (! is_string($url) || trim($url) === '' || ! is_string($key) || trim($key) === '' || ! Route::has('support-client.create')) {
            return;
        }

        $request = request();
        if ($request->user() === null) {
            return;
        }

        $this->ref = LastError::current()['ref'] ?? null;
        $from = app(ContextCollector::class)->pageUrl($request, $request->fullUrl());

        $this->href = route('support-client.create', array_filter(['from' => $from, 'error' => $this->ref]));
        $this->visible = true;
    }
}
