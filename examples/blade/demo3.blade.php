@useState($isOpen, false)

@wrapper
<div @hydrate('div-1') @class(['demo3-component']) @click(toggle())>
    Status: @startMarker('output', 'div-1-output-1'){{ $isOpen ? 'Open' : 'Closed' }}@endMarker('output', 'div-1-output-1')
</div>
@endWrapper