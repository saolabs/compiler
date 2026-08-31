"""Sinh corpus tổ hợp cú pháp cho sweep. Xem tests/Tools/sweep/README.md."""
import os

cases = {

 # điều khiển lồng nhau
 "if-in-foreach": "@states({items:[1],x:1})\n<template><ul>@foreach(items as i)<li>@if(x>0)<b>{{ i }}</b>@endif</li>@endforeach</ul></template>",
 "foreach-in-if": "@states({items:[1],x:1})\n<template><div>@if(x>0)<ul>@foreach(items as i)<li>{{ i }}</li>@endforeach</ul>@endif</div></template>",
 "nested-foreach": "@states({a:[1]})\n<template>@foreach(a as x)@foreach(a as y)<i>{{ y }}</i>@endforeach@endforeach</template>",
 "if-elseif-else": "@states({x:1})\n<template>@if(x>2)<a>1</a>@elseif(x>1)<b>2</b>@else<c>3</c>@endif</template>",
 "switch-case": "@states({x:1})\n<template>@switch(x)@case(1)<a>one</a>@break@case(2)<b>two</b>@break@default<c>other</c>@endswitch</template>",
 # self-closing & void
 "void-tags": "@states({x:1})\n<template><div><br><hr><img src='a.png'><input type='text'></div></template>",
 "self-closing": "@states({x:1})\n<template><div><span/><em /></div></template>",
 # thuộc tính
 "attr-quotes": "@states({x:1})\n<template><div data-a=\"1\" data-b='2' data-c=3>{{ x }}</div></template>",
 "attr-with-gt": "@states({n:1})\n<template><div @class({'hot': n > 10})>{{ n }}</div></template>",
 "attr-multiline": "@states({x:1})\n<template><div\n  class='a'\n  @class({'b': x>0})\n>{{ x }}</div></template>",
 "boolean-attr": "@states({x:1})\n<template><input disabled readonly type='text'></template>",
 # output
 "raw-output": "@states({x:1})\n<template><p>{!! x !!}</p><p>{{ x }}</p></template>",
 "adjacent-output": "@states({a:1,b:2})\n<template><p>{{ a }}{{ b }}</p></template>",
 "output-in-attr": "@states({x:1})\n<template><div title=\"{{ x }}\">y</div></template>",
 # text/whitespace
 "text-around": "@states({x:1})\n<template><p>trước {{ x }} sau</p></template>",
 "entity": "@states({x:1})\n<template><p>&amp; &lt; &gt; {{ x }}</p></template>",
 "unicode": "@states({x:1})\n<template><p>Tiếng Việt có dấu — {{ x }}</p></template>",
 "pre-tag": "@states({x:1})\n<template><pre>dòng1\ndòng2 {{ x }}</pre></template>",
 # comment/verbatim
 "comment-with-tags": "@states({x:1})\n<template>{{-- <div>bị che</div> --}}<p>{{ x }}</p></template>",
 "verbatim-tags": "@states({x:1})\n<template>@verbatim<div>{{ x }}</div>@endverbatim<p>{{ x }}</p></template>",
 # component
 "component-tag": "@import(__template__ + 'u.item' as UItem)\n@states({x:1})\n<template><div><UItem :v=\"x\" /></div></template>",
 "component-children": "@import(__template__ + 'u.g' as UG)\n@states({x:1})\n<template><UG :v=\"x\"><b>{{ x }}</b></UG></template>",
 # key
 "foreach-key": "@states({items:[]})\n<template>@foreach(items as i)@key(i.id)<li>{{ i.name }}</li>@endforeach</template>",
 "foreach-nokey": "@states({items:[]})\n<template>@foreach(items as i)<li>{{ i }}</li>@endforeach</template>",
 # event
 "event-handler": "@states({x:1})\n<template><button @click(setX(x+1))>{{ x }}</button></template>",
 # deep nesting
 "deep-nest": "@states({x:1})\n<template><div><div><div><div><p>{{ x }}</p></div></div></div></div></template>",

 "key-inline-nested": "@states({a:[]})\n<template>@foreach(a as x)@key(x.id)<li>@foreach(a as y)@key(y.id)<b>{{ y }}</b>@endforeach</li>@endforeach</template>",
 "wrapper-inline": "@states({x:1})\n<template>@wrapper<b>{{ x }}</b>@endwrapper</template>",
 "if-attr-directive": "@states({x:1})\n<template><div @if(x>0) data-a='1' @endif>{{ x }}</div></template>",
 "output-inside-tag-attr": "@states({x:1})\n<template><div class=\"a{{ x }}b\">y</div></template>",
 "nested-quotes-attr": "@states({x:1})\n<template><div title='say \"hi\"' @class({'a':x>0})>{{ x }}</div></template>",
 "empty-template": "@states({x:1})\n<template></template>",
 "only-text": "@states({x:1})\n<template>chỉ có chữ</template>",
 "multi-root": "@states({x:1})\n<template><a>1</a><b>2</b><c>3</c></template>",
 "comment-between": "@states({x:1})\n<template><a>1</a>{{-- giữa --}}<b>2</b></template>",
 "verbatim-directive": "@states({x:1})\n<template>@verbatim @if(x)<b>a</b>@endif @endverbatim<p>{{ x }}</p></template>",
 "if-no-space": "@states({x:1})\n<template>@if(x>0)<b>a</b>@endif</template>",
 "if-extra-space": "@states({x:1})\n<template>@if  (x>0)  <b>a</b>  @endif</template>",
 "table-structure": "@states({r:[]})\n<template><table><tbody>@foreach(r as x)<tr><td>{{ x }}</td></tr>@endforeach</tbody></table></template>",
 "svg-inline": "@states({x:1})\n<template><svg viewBox='0 0 1 1'><path d='M0 0'/></svg><p>{{ x }}</p></template>",
 "deep-cond-nest": "@states({x:1})\n<template>@if(x>0)@if(x>1)<a>1</a>@else<b>2</b>@endif@else<c>3</c>@endif</template>",
 "break-in-switch": "@states({x:1})\n<template>@switch(x)@case(1)<a>1</a>@break@default<b>d</b>@endswitch</template>",
 "text-with-at": "@states({x:1})\n<template><p>email@domain.com và {{ x }}</p></template>",
 "at-in-attr": "@states({x:1})\n<template><a href='mailto:a@b.com'>{{ x }}</a></template>",

 "computed-chain": "@states({a:1,b:2})\n@computed(c = a + b)\n@computed(d = c * 2)\n<template><p>{{ c }} {{ d }}</p></template>",
 "vars-let-const": "@vars(v = 1)\n@let(l = 2)\n@const(C = 3)\n@states({s:4})\n<template><p>{{ v }}{{ l }}{{ C }}{{ s }}</p></template>",
 "states-obj-nested": "@states({f:{a:{b:1}}})\n<template><p>{{ f.a.b }}</p></template>",
 "scoped-style": "@states({x:1})\n<template><p class='k'>{{ x }}</p></template>\n<style scoped>.k{color:red}</style>",
 "global-style": "@states({x:1})\n<template><p class='k'>{{ x }}</p></template>\n<style>.k{color:red}</style>",
 "section-block": "@states({x:1})\n<template>@section('a')<b>{{ x }}</b>@endsection</template>",
 "await-fetch": "@fetch(url = '/api')\n@states({x:1})\n<template><p>{{ x }}</p></template>",
 "script-setup-method": "@states({x:1})\n<template><button @click(inc())>{{ x }}</button></template>\n<script setup>\nexport default { inc(){ } }\n</script>",
 "if-with-and-or": "@states({a:1,b:2})\n<template>@if(a > 0 && b < 5)<p>ok</p>@endif</template>",
 "ternary-output": "@states({x:1})\n<template><p>{{ x > 0 ? 'có' : 'không' }}</p></template>",
 "string-with-braces": "@states({x:1})\n<template><p>{{ '{a}' }}</p></template>",
 "nested-component-loop": "@import(__template__ + 'u.i' as UI)\n@states({a:[]})\n<template>@foreach(a as x)@key(x.id)<UI :v=\"x\" />@endforeach</template>",
 "foreach-index": "@states({a:[]})\n<template>@foreach(a as x)<li>{{ loop.index }} {{ x }}</li>@endforeach</template>",
 "attr-colon-shorthand": "@states({x:1})\n<template><div :title=\"x\" :data-v=\"x + 1\">y</div></template>",
 "class-style-attr-combo": "@states({x:1})\n<template><div class='a' @class({'b':x>0}) :class=\"x\" style='color:red' @style({color: 'blue'}) :style=\"x\" @attr({'d':1}) :d2=\"x\">y</div></template>",
 "empty-foreach-body": "@states({a:[]})\n<template>@foreach(a as x)@endforeach</template>",
 "if-empty-body": "@states({x:1})\n<template>@if(x)@endif</template>",

 "ts-lang": "@states({x:1})\n<template><p>{{ x }}</p></template>",
 "prerender-await": "@await\n@states({x:1})\n<template><p>{{ x }}</p></template>",
 "many-siblings": "@states({x:1})\n<template><div>" + "".join(f"<p>{{{{ x }}}}</p>" for _ in range(12)) + "</div></template>",
 "deep-10": "@states({x:1})\n<template>" + "<div>"*10 + "{{ x }}" + "</div>"*10 + "</template>",
 "cond-siblings": "@states({x:1})\n<template><div>@if(x>0)<a>1</a>@endif@if(x>1)<b>2</b>@endif@if(x>2)<c>3</c>@endif</div></template>",
 "loop-siblings": "@states({a:[]})\n<template><div>@foreach(a as i)<p>{{ i }}</p>@endforeach@foreach(a as j)<q>{{ j }}</q>@endforeach</div></template>",
 "mixed-text-el": "@states({x:1})\n<template><div>chữ<b>đậm</b>chữ<i>nghiêng</i>chữ</div></template>",
 "attr-no-quote-gt": "@states({n:1})\n<template><div @class({'a': n > 1, 'b': n < 5})>{{ n }}</div></template>",
 "nested-parens-attr": "@states({n:1})\n<template><div @class({'a': (n + 1) > (2 * 1)})>{{ n }}</div></template>",
 "output-with-fn": "@states({s:'a'})\n<template><p>{{ upper(s) }}</p></template>",
 "key-expression": "@states({a:[]})\n<template>@foreach(a as i)@key(i.id + '-' + i.n)<li>{{ i }}</li>@endforeach</template>",
 "section-in-if": "@states({x:1})\n<template>@if(x>0)@section('s')<b>{{ x }}</b>@endsection@endif</template>",
 "if-in-section": "@states({x:1})\n<template>@section('s')@if(x>0)<b>{{ x }}</b>@endif@endsection</template>",
 "wrapper-nested-if": "@states({x:1})\n<template>@wrapper@if(x>0)<b>{{ x }}</b>@endif@endwrapper</template>",

 "computed-in-loop": "@states({a:[]})\n@computed(n = a.length)\n<template><p>{{ n }}</p>@foreach(a as i)<li>{{ i }}</li>@endforeach</template>",
 "scoped-style-loop": "@states({a:[]})\n<template>@foreach(a as i)<li class='row'>{{ i }}</li>@endforeach</template>\n<style scoped>.row{color:red}</style>",
 "await-with-content": "@await\n@states({x:1})\n<template><div><p>{{ x }}</p><span>b</span></div></template>",
 "unicode-attr": "@states({x:1})\n<template><div title='Tiếng Việt có dấu' data-k='ăâđêôơư'>{{ x }}</div></template>",
 "long-mixed": "@states({a:[],x:1})\n<template><div>@if(x>0)<h1>t</h1>@foreach(a as i)@key(i.id)<li>@if(i)<b>{{ i }}</b>@else<i>e</i>@endif</li>@endforeach@endif</div></template>",
 "sibling-loops-keys": "@states({a:[],b:[]})\n<template>@foreach(a as i)@key(i.id)<p>{{ i }}</p>@endforeach@foreach(b as j)@key(j.id)<q>{{ j }}</q>@endforeach</template>",
 "attr-with-newline-expr": "@states({n:1})\n<template><div\n @class({\n 'a': n > 1,\n 'b': n < 5\n })\n>{{ n }}</div></template>",
 "empty-attrs": "@states({x:1})\n<template><div class='' id=''>{{ x }}</div></template>",
 "nested-ternary": "@states({x:1})\n<template><p>{{ x > 2 ? 'a' : (x > 1 ? 'b' : 'c') }}</p></template>",
 "concat-in-attr": "@states({a:'x',b:'y'})\n<template><div :title=\"a + '-' + b\">{{ a + '-' + b }}</div></template>",
 "concat-in-class": "@states({a:'x'})\n<template><div @class({'p-' : true})>{{ a }}</div></template>",
}

os.makedirs('cases', exist_ok=True)
for _n, _s in cases.items():
    open(f'cases/{_n}.sao', 'w', encoding='utf-8').write(_s + '\n')
print(len(cases), 'ca')
