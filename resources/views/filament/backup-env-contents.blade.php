@if (filled($error))
    <p class="text-sm text-danger-600 dark:text-danger-400">{{ $error }}</p>
@else
    <textarea
        readonly
        rows="24"
        spellcheck="false"
        autocomplete="off"
        class="fi-input block w-full font-mono text-sm"
        style="min-height: 28rem; white-space: pre"
    >{{ $contents }}</textarea>
@endif
