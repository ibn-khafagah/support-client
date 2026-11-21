<?php

namespace IbnKhafagah\SupportClient\Laravel\View;

use IbnKhafagah\SupportClient\Laravel\Contracts\ResolvesSupportUser;
use IbnKhafagah\SupportClient\Laravel\Unread;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Throwable;

/**
 * <x-support-badge /> for the host's navigation: how many of the user's
 * tickets have something new since they last opened them (99+ above 99).
 * Read from this app's cache, never from the CRM, so it costs nothing on
 * every page. Renders nothing for a guest, at zero, or if anything goes
 * wrong: the host's layout must never fail because of it.
 */
class Badge extends Component
{
    public int $count = 0;

    public string $label = '';

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
        return view('support-client::components.badge');
    }

    private function prepare(): void
    {
        $user = request()->user();
        if ($user === null) {
            return;
        }

        $reporter = app(ResolvesSupportUser::class)->resolve($user);
        if (! $reporter->hasUsableId()) {
            return;
        }

        $this->count = app(Unread::class)->count($reporter->id);
        $this->label = Ui::t('badge.label', ['count' => $this->count]);
        $this->visible = $this->count > 0;
    }
}
