@exec($__ONE_COMPONENT_REGISTRY__ = []) {{-- Khai báo để sử dụng các component đã đăng ký trong $__ONE_COMPONENT_REGISTRY__ --}}

<?php if(!array_key_exists('users', get_defined_vars())) $users = []; ?>
@wrapper
@fetch('/api/users')
@await
<div @class([$__VIEW_ID__ . '-e1'])>Có @startMarker('output', 'e1o1'){{ count($users) }}@endMarker('output', 'e1o1') người dùng</div>
@endWrapper
