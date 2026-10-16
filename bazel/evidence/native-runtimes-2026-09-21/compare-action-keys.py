import hashlib,json,sys
from pathlib import Path
def keys(path):
 graph=json.loads(Path(path).read_text());fragments={str(x['id']):x for x in graph['pathFragments']};memo={}
 def full(key):
  key=str(key)
  if key not in memo:
   node=fragments[key];memo[key]=(full(node['parentId'])+'/' if node.get('parentId') else '')+node['label']
  return memo[key]
 artifacts={str(x['id']):full(x['pathFragmentId']) for x in graph['artifacts']}
 dep_sets={str(x['id']):x for x in graph.get('depSetOfFiles',[])};digests={}
 def inputs(key):
  key=str(key)
  if key not in digests:
   node=dep_sets[key]
   value=dict(files=sorted(artifacts[str(i)] for i in node.get('directArtifactIds',[])),children=sorted(inputs(i) for i in node.get('transitiveDepSetIds',[])))
   digests[key]=hashlib.sha256(json.dumps(value,sort_keys=True).encode()).hexdigest()
  return digests[key]
 return {(action['mnemonic'],tuple(artifacts[str(i)] for i in action.get('outputIds',[]))):dict(action_key=action.get('actionKey'),input_groups=sorted(inputs(i) for i in action.get('inputDepSetIds',[]))) for action in graph['actions'] if action.get('outputIds')}
a,b=keys(sys.argv[1]),keys(sys.argv[2])
changed=[dict(mnemonic=k[0],outputs=k[1],before=a.get(k),after=b.get(k)) for k in sorted(a.keys()|b.keys()) if k not in a or k not in b or a[k]!=b[k]]
print(json.dumps(dict(before_actions=len(a),after_actions=len(b),compared='Action keys, output paths and declared input dependency groups',changed=changed,unchanged=not changed),indent=2))
raise SystemExit(bool(changed))
