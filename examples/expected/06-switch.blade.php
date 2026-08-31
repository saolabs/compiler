@exec($__ONE_COMPONENT_REGISTRY__ = []) {{-- Khai báo để sử dụng các component đã đăng ký trong $__ONE_COMPONENT_REGISTRY__ --}}

@useState($runtime, 'blade')
@wrapper
@startMarker('reactive', 'r1', ['stateKey' => ['runtime'], 'type' => 'switch'])
@switch($runtime)
        @case('blade')
            <span @class([$__VIEW_ID__ . '-r1k11'])>Server</span>
            @break
        @case('js')
            <span @class([$__VIEW_ID__ . '-r1k21'])>Client</span>
            @break
        @default
            <span @class([$__VIEW_ID__ . '-r1k31'])>Không rõ</span>
    @endswitch
    @endMarker('reactive', 'r1')
@endWrapper
