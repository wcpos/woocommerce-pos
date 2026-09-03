import sys, json
rows=[json.loads(l) for l in open(sys.argv[1]) if l.strip()]
uri = sys.argv[2] if len(sys.argv) > 2 else 'store/v1/checkout'
print("== per-request rows for", uri)
for d in rows:
    if uri in d['uri'] and d['status'] == 200:
        print(f"  mode={d['mode']:5} total={d['total_ms']:7.1f}ms q={d['total_queries']:4} pos_ms={d['pos_excl_ms']:6} pos_q={d['pos_queries']:3} fired={d['pos_callbacks_fired']} calls={d['pos_calls']} unwrapped={len(d['pos_unwrapped'])} mem={d['peak_mem_mb']}")
on=[d for d in rows if uri in d['uri'] and d['status']==200 and d['mode']=='on']
d=on[len(on)//2]
print(f"\n== callbacks for one ON request ({d['total_ms']}ms, pos {d['pos_excl_ms']}ms / {d['pos_queries']} q)")
for u in d['pos_unwrapped']: print("   UNWRAPPED", u)
for r in d['callbacks']:
    if r['excl_ms'] >= 0.3 or r['queries'] > 0:
        print(f"  {r['excl_ms']:8.2f}ms {r['calls']:3d}x q={r['queries']:<3} {r['hook']} -> {r['cb']}")
        seen=set()
        for s in r['sql']:
            k=s['q'][:70]
            if k in seen: continue
            seen.add(k)
            if len(seen) > 5: break
            print(f"       SQL {s['ms']}ms {s['q'][:150]}")
cheap=[r for r in d['callbacks'] if r['excl_ms'] < 0.3 and r['queries'] == 0]
print(f"\n  + {len(cheap)} callbacks each < 0.3 ms and 0 queries ({sum(r['calls'] for r in cheap)} calls, {sum(r['excl_ms'] for r in cheap):.2f} ms total)")
