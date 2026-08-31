@exec($__ONE_COMPONENT_REGISTRY__ = []) {{-- Khai báo để sử dụng các component đã đăng ký trong $__ONE_COMPONENT_REGISTRY__ --}}

@useState($items, [])
@wrapper
<ul @class([$__VIEW_ID__ . '-e1'])>
    @startMarker('reactive', 'e1l1', ['stateKey' => ['items'], 'type' => 'foreach'])
    @foreach($items as $item)
        <li @class([$__VIEW_ID__ . "-e1l11-{$item->id}"])>@startMarker('output', "e1l11o1-{$item->id}"){{ $item->name }}@endMarker('output', "e1l11o1-{$item->id}")</li>
    @endforeach
    @endMarker('reactive', 'e1l1')
    </ul>
@endWrapper
