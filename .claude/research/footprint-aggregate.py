import sys, json, statistics as st, collections
rows=[json.loads(l) for l in open(sys.argv[1]) if l.strip()]
g=collections.defaultdict(list)
for d in rows: g[(d['method'],d['uri'].split('?')[0],d['mode'])].append(d)
print(f"{'req':45} {'mode':4} {'n':>2} {'srv_ms med':>10} {'queries med':>11} {'POS ms':>7} {'POS q':>5} {'POS cb':>6} {'POS calls':>9} {'pos files':>9} {'mem':>5}")
for k in sorted(g):
    v=g[k]; med=lambda f: st.median([f(d) for d in v])
    print(f"{k[0]+' '+k[1]:45} {k[2]:4} {len(v):>2} {med(lambda d:d['total_ms']):10.1f} {med(lambda d:d['total_queries']):11.0f} {med(lambda d:d['pos_excl_ms']):7.2f} {med(lambda d:d['pos_queries']):5.0f} {med(lambda d:d['pos_callbacks_fired']):6.0f} {med(lambda d:d['pos_calls']):9.0f} {med(lambda d:d['pos_included_files']):9.0f} {med(lambda d:d['peak_mem_mb']):5.1f}")
