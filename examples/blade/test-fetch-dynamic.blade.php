@vars($items = [])

@wrapper
@fetch("https://api.example.com/items")
<div @hydrate('div-1')>
    <h2 @hydrate('div-1-h2-1')>Items</h2>
    @foreach($items as $item)
        <p @hydrate("div-1-foreach-1-{$loop->index}-p-1")>{{ $item->name }}</p>
    @endforeach
</div>
@endWrapper