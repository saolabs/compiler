<?php if(!isset($a) || (!$a && $a !== false)) $a = 0; if(!isset($cProp) || (!$cProp && $cProp !== false)) $cProp = 0; ?>
<?php if(!isset($b) || (!$b && $b !== false)) $b = 0; if(!isset($d) || (!$d && $d !== false)) $d = 0; ?>
@useState($countState, 0)
@useState($eState, $b)
@const([$count, $setCount] = useState(0))
@let($testVar = 'This is a test variable')
@const([$message, $setMessage] = useState('Hello, Saola!'))
@let($textContent = $message . ' Count is ' . $count)

@wrapper
<div @hydrate('div-1') @class(['counter-component'])>
    <h4 @hydrate('div-1-h4-1')>Count: <span @hydrate('div-1-h4-1-span-1') @attr(['id' => 'counter-value', 'data-count' => $count])>@startMarker('output', 'div-1-h4-1-span-1-output-1'){{ $count }}@endMarker('output', 'div-1-h4-1-span-1-output-1')</span></h4>
    <div @hydrate('div-1-div-2') @class(['btn-group'])>
        <button @hydrate('div-1-div-2-button-1') @class(['btn', 'btn-primary']) @click(decrement())>-</button>
        <button @hydrate('div-1-div-2-button-2') @class(['btn', 'btn-primary']) @click(increment())>+</button>
        <button @hydrate('div-1-div-2-button-3') @class(['btn', 'btn-primary']) @click(reset())>Reset</button>
    </div>
</div>
@endWrapper