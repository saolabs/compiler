@exec($__ONE_COMPONENT_REGISTRY__ = []) {{-- Khai báo để sử dụng các component đã đăng ký trong $__ONE_COMPONENT_REGISTRY__ --}}

<?php if(!array_key_exists('label', get_defined_vars())) $label = ''; if(!array_key_exists('value', get_defined_vars())) $value = 0; if(!array_key_exists('items', get_defined_vars())) $items = ['']; ?>
@wrapper
<div @class([$__VIEW_ID__ . '-e1', 'my-4'])>
        <label @class([$__VIEW_ID__ . '-e11'])>@startMarker('output', 'e11o1'){{ $label }}@endMarker('output', 'e11o1')</label>
        <input @class([$__VIEW_ID__ . '-e12']) @attr(['type' => 'text', 'v-model' => 'value'])>
    </div>
    <div @class([$__VIEW_ID__ . '-e2', 'mt-4'])>
        <ul @class([$__VIEW_ID__ . '-e21'])>
            @startMarker('reactive', 'e21l1', ['stateKey' => ['items'], 'type' => 'foreach'])
            @foreach($items as $item)
                <li @class([$__VIEW_ID__ . "-e21l11-{$loop->index}"]) @attr(['key' => $item])>@startMarker('output', "e21l11o1-{$loop->index}"){{ $item }}@endMarker('output', "e21l11o1-{$loop->index}")</li>
            @endforeach
            @endMarker('reactive', 'e21l1')
        </ul>
    </div>
    <div @class([$__VIEW_ID__ . '-e3', 'bg-red-500'=> $value < 10,             'bg-green-500'=> $value >= 10,             'bg-blue-500'=> $value >= 20]) @style([             'color'=> $value > 10 ? 'blue' : 'red',             'font-size'=> $value . 'px'         ])>
        @startMarker('output', 'e3o1'){{ $value }}@endMarker('output', 'e3o1')
    </div>
@endWrapper
