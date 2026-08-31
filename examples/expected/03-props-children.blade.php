@exec($__ONE_COMPONENT_REGISTRY__ = []) {{-- Khai báo để sử dụng các component đã đăng ký trong $__ONE_COMPONENT_REGISTRY__ --}}

@vars($__ONE_CHILDREN_CONTENT__ = '')
<?php if(!isset($title) || (!$title && $title !== false)) $title = ''; if(!isset($tone) || (!$tone && $tone !== false)) $tone = 'default'; ?>
@wrapper
<article @class([$__VIEW_ID__ . '-e1', 'card', 'card-accent'=> $tone === 'accent'])>
        <h2 @class([$__VIEW_ID__ . '-e11'])>@startMarker('output', 'e11o1'){{ $title }}@endMarker('output', 'e11o1')</h2>
        <div @class([$__VIEW_ID__ . '-e12', 'card-body'])>{!! $__ONE_CHILDREN_CONTENT__ !!}</div>
    </article>
@endWrapper
