@exec($__ONE_COMPONENT_REGISTRY__ = []) {{-- Khai báo để sử dụng các component đã đăng ký trong $__ONE_COMPONENT_REGISTRY__ --}}

@useState($status, 'idle')
@wrapper
<div @class([$__VIEW_ID__ . '-e1'])>
    @startMarker('reactive', 'e1r1', ['stateKey' => ['status'], 'type' => 'if'])
    @if($status === 'ready')
        <p @class([$__VIEW_ID__ . '-e1r1k11', 'ok'])>Sẵn sàng</p>
    @elseif($status === 'idle')
        <p @class([$__VIEW_ID__ . '-e1r1k21', 'idle'])>Đang chờ</p>
    @else
        <p @class([$__VIEW_ID__ . '-e1r1k31', 'err'])>Lỗi</p>
    @endif
    @endMarker('reactive', 'e1r1')
    </div>
@endWrapper
