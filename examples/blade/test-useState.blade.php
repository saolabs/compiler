@useState($users, [])
@const([$userList, $setUserList] = useState([]))
@let([$loading, $setLoading] = useState(false))

@wrapper
<div @hydrate('div-1') @class(['test'])>
    <h2 @hydrate('div-1-h2-1')>Test @startMarker('output', 'div-1-h2-1-output-1'){{ $users }}@endMarker('output', 'div-1-h2-1-output-1')</h2>
</div>
@endWrapper