@extends('support-client::layout')
@use('IbnKhafagah\SupportClient\Laravel\View\Ui')

@section('title', Ui::t('create.title'))

@section('content')
    @php
        $inline = ['subject', 'category_id', 'priority', 'body', 'attachments'];
        $otherErrors = [];
        foreach ($errors->getMessages() as $field => $messages) {
            if (! in_array(explode('.', (string) $field)[0], $inline, true)) {
                array_push($otherErrors, ...$messages);
            }
        }
        $attachmentErrors = array_merge($errors->get('attachments'), $errors->get('attachments.*') ? array_merge(...array_values($errors->get('attachments.*'))) : []);
        $groups = [];
        foreach ($categories as $category) {
            $groups[(string) ($category['department'] ?? '')][] = $category;
        }
        // a reported error pre-selects the "System error" category; the user's own choice wins
        $selectedCategory = (string) old('category_id', $preselectedCategory ?? '');
        $selectedPriority = (string) old('priority', 'normal');
        $state = (string) ($state ?? '');
        $canCreate = $canCreate ?? true;
        $supportMessage = is_string($support['message'] ?? null) ? trim($support['message']) : '';
    @endphp

    <div class="sc-head">
        <div>
            <h1>{{ Ui::t('create.title') }}</h1>
            @if ($canCreate)
                <p class="sc-muted sc-small" style="margin:4px 0 0">{{ Ui::t('create.intro') }}</p>
            @endif
        </div>
    </div>

    @if (! empty($offline))
        <div class="sc-alert sc-alert-warn" role="status">
            <strong>{{ Ui::t('create.offline_title') }}</strong>
            <p>{{ Ui::t('create.offline_note') }}</p>
        </div>
    @endif

    @if ($state === 'suspended')
        <div class="sc-alert sc-alert-warn" role="note">
            <strong>{{ Ui::t('problems.suspended_title') }}</strong>
            @if ($supportMessage !== '')
                <p dir="auto">{{ $supportMessage }}</p>
            @endif
            @if (! empty($support['covered_until']))
                <p>{{ Ui::t('create.covered_until', ['date' => Ui::day($support['covered_until'])]) }}</p>
            @endif
            <p>{{ $canCreate ? Ui::t('create.billing_only') : Ui::t('create.no_billing') }}</p>
            @if (! empty($support['renew_url']))
                <a class="sc-btn sc-btn-primary" href="{{ $support['renew_url'] }}" target="_blank" rel="noopener noreferrer">{{ Ui::t('problems.renew') }}</a>
            @endif
        </div>
    @elseif ($state === 'revoked')
        <div class="sc-alert sc-alert-bad" role="alert">
            <strong>{{ Ui::t('problems.revoked_title') }}</strong>
            <p dir="auto">{{ $supportMessage !== '' ? $supportMessage : Ui::t('show.reply_revoked') }}</p>
        </div>
    @endif

    @if ($canCreate)
    @if ($errors->any())
        <div class="sc-alert sc-alert-bad" role="alert">
            <strong>{{ Ui::t('create.fix_errors') }}</strong>
            @if ($otherErrors !== [])
                <ul>
                    @foreach ($otherErrors as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @if (! empty($lastError))
        <div class="sc-alert sc-alert-info" role="note">
            <strong>{{ Ui::t('create.error_title') }} <span class="sc-mono">{{ $lastError['ref'] }}</span></strong>
            <p>{{ Ui::t('create.error_note', ['time' => Ui::date($lastError['at'])]) }}</p>
        </div>
    @endif

    <form class="sc-card" method="post" action="{{ route('support-client.store') }}" enctype="multipart/form-data"
          onsubmit="var b=this.querySelector('[type=submit]');if(b){b.disabled=true;}">
        @csrf
        {{-- the page the user came from (this app's own pages only) and the reported error --}}
        <input type="hidden" name="from" value="{{ $from ?? '' }}">
        <input type="hidden" name="error" value="{{ $errorRef ?? '' }}">

        <div class="sc-field">
            <label class="sc-label" for="sc-subject">{{ Ui::t('labels.subject') }}</label>
            <input class="sc-input" id="sc-subject" name="subject" type="text" maxlength="200" required dir="auto"
                   value="{{ old('subject') }}" placeholder="{{ Ui::t('create.subject_placeholder') }}"
                   @error('subject') aria-invalid="true" aria-describedby="sc-subject-error" @enderror>
            @error('subject')
                <p class="sc-error" id="sc-subject-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="sc-grid2">
            <div class="sc-field">
                <label class="sc-label" for="sc-category">{{ Ui::t('labels.category_id') }}</label>
                <select class="sc-input" id="sc-category" name="category_id" required
                        @error('category_id') aria-invalid="true" aria-describedby="sc-category-error" @enderror>
                    <option value="">{{ Ui::t('create.choose_category') }}</option>
                    @foreach ($groups as $department => $items)
                        <optgroup label="{{ Ui::department($department) }}">
                            @foreach ($items as $category)
                                <option value="{{ $category['id'] }}" @selected($selectedCategory === (string) $category['id'])>{{ $category['name'] }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                @if ($categories === [])
                    <p class="sc-hint">{{ Ui::t('create.no_categories') }}</p>
                @endif
                @error('category_id')
                    <p class="sc-error" id="sc-category-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="sc-field">
                <label class="sc-label" for="sc-priority">{{ Ui::t('labels.priority') }}</label>
                <select class="sc-input" id="sc-priority" name="priority" required
                        @error('priority') aria-invalid="true" aria-describedby="sc-priority-error" @enderror>
                    @foreach ($priorities as $priority)
                        <option value="{{ $priority }}" @selected($selectedPriority === $priority)>{{ Ui::priority($priority) }}</option>
                    @endforeach
                </select>
                @error('priority')
                    <p class="sc-error" id="sc-priority-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="sc-field">
            <label class="sc-label" for="sc-body">{{ Ui::t('labels.body') }}</label>
            <textarea class="sc-input" id="sc-body" name="body" rows="8" maxlength="20000" required dir="auto"
                      placeholder="{{ Ui::t('create.body_placeholder') }}"
                      @error('body') aria-invalid="true" aria-describedby="sc-body-error" @enderror>{{ old('body') }}</textarea>
            @error('body')
                <p class="sc-error" id="sc-body-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="sc-field">
            <label class="sc-label" for="sc-attachments">{{ Ui::t('labels.attachments') }}</label>
            <input class="sc-input" id="sc-attachments" name="attachments[]" type="file" multiple
                   accept="{{ implode(',', array_map(fn ($ext) => '.'.$ext, $limits['extensions'])) }}"
                   aria-describedby="sc-attachments-hint" @if ($attachmentErrors !== []) aria-invalid="true" @endif>
            <p class="sc-hint" id="sc-attachments-hint">{{ Ui::t('create.attachments_hint', ['count' => $limits['files'], 'size' => $limits['file_mb'], 'total' => $limits['total_mb'], 'types' => implode(', ', $limits['extensions'])]) }}</p>
            @foreach (array_unique($attachmentErrors) as $message)
                <p class="sc-error">{{ $message }}</p>
            @endforeach
        </div>

        <p class="sc-hint" style="margin:0 0 16px">{{ Ui::t('create.auto_details') }}</p>

        <div class="sc-actions">
            <button class="sc-btn sc-btn-primary" type="submit">{{ Ui::t('create.submit') }}</button>
            <a class="sc-btn sc-btn-link" href="{{ route('support-client.index') }}">{{ Ui::t('create.cancel') }}</a>
        </div>
    </form>
    @endif
@endsection
