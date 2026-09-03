import csv, datetime, re, json, xlrd, collections
CATS={1:'Commission',2:'Contractors',3:'Professional Services',4:'Online Services',5:'Travel',6:'Samples',7:'Marketing',8:'Supplies',9:'Other Expenses',10:'Bank Fees',11:'Domain',12:'Hosting',13:'Product Development',14:'Courses'}
nm={}
for l in open('namemap_2025.txt'):
    k,v=l.rstrip('\n').rsplit('|',1); nm[k]=int(v)
def norm(s):
    s=s.strip().lower()
    s=re.sub(r'^(paypal|sq|sp|tst|dd|ls|pp)\s*\*\s*','',s)
    s=re.sub(r'\s*#\s*\d+.*$','',s)
    return re.sub(r'\s+',' ',s).strip()
def lookup(merchant):
    n=norm(merchant)
    if n in nm: return nm[n],'exact'
    words=n.split(' ')
    for k in range(len(words)-1,0,-1):
        p=' '.join(words[:k])
        if p in nm and len(p)>=3: return nm[p],'prefix'
    for key,cat in nm.items():
        if len(key)>=5 and n.startswith(key): return cat,'prefix'
    return None,'unknown'
D0,D1=datetime.date(2026,1,1),datetime.date(2026,7,31)
ledger=[]; review=[]
def pd(s): return datetime.datetime.strptime(s.strip(),'%d %b, %Y').date()
def amt(s): return float(s.replace(',',''))
rows=list(csv.DictReader(open('/mnt/user-data/uploads/Downloads/report_2026-08-27_21-24-49.csv', encoding='utf-8-sig')))
tid=[k for k in rows[0] if k.startswith('Transaction')][0]
for r in rows:
    d=pd(r['Date']); a=amt(r['Amount']); desc=r['Description'].strip(); st=r['Status'].strip(); ref=r[tid].strip()
    if not (D0<=d<=D1) or st!='Completed': continue
    if r['Currency'].strip()!='USD': review.append(('payoneer',d,desc,a,'non-USD',ref)); continue
    m=re.match(r'Card charge \((.*)\)$',desc)
    if m:
        merchant=m.group(1)
        if a>0: review.append(('payoneer',d,desc,a,'positive card charge (refund?)',ref)); continue
        cat,how=lookup(merchant)
        name=re.sub(r'\s*#\s*\d+.*$','',re.sub(r'^(PAYPAL|SQ|SP|TST|DD|LS|PP)\s*\*\s*','',merchant,flags=re.I)).strip()
        name=' '.join(w if (w.isupper() and len(w)<=3) else w.capitalize() for w in name.split(' '))
        ledger.append(dict(src='payoneer',date=d.isoformat(),name=name,note=desc,amount=round(-a,2),cat=cat,how=how,ref=ref,pm=2))
    elif desc.startswith('Payment to Artashes Papikyan'):
        ledger.append(dict(src='payoneer',date=d.isoformat(),name='Artashes Papikyan',note=desc,amount=round(-a,2),cat=2,how='rule',ref=ref,pm=2))
    elif desc.startswith('Withdrawal to CrediCorp'):
        fee=round(-a-round(-a,-3),2)
        review.append(('payoneer',d,desc,a,'internal transfer to CrediCorp, not an expense; implied fee %.2f entered as Bank Fees'%fee,ref))
        if fee>0: ledger.append(dict(src='payoneer',date=d.isoformat(),name='Payoneer',note='Withdrawal fee: '+desc,amount=fee,cat=10,how='rule',ref=ref,pm=2))
    elif desc.startswith('Maintenance fee'):
        ledger.append(dict(src='payoneer',date=d.isoformat(),name='Payoneer',note=desc,amount=round(-a,2),cat=10,how='rule',ref=ref,pm=2))
    elif desc.startswith('Payment from'):
        pass
    else:
        review.append(('payoneer',d,desc,a,'unhandled type',ref))
sh=xlrd.open_workbook('/mnt/user-data/uploads/Downloads/report-NBcYvkRguDM.xls').sheet_by_index(0)
for i in range(sh.nrows):
    v=sh.row_values(i); g=lambda j: str(v[j]).strip() if j<len(v) else ''
    try: d=datetime.datetime.strptime(g(1),'%d/%m/%Y').date()
    except: continue
    if not (D0<=d<=D1): continue
    tx,concepto,refn=g(6),g(11),g(16)
    ret=float(g(18).replace(',','') or 0); dep=float(g(23).replace(',','') or 0)
    if ret<=0: continue
    src=concepto or tx
    cat,how=lookup(src)
    if cat is None and concepto:
        cat2,how2=lookup(tx)
        if cat2: cat,how=cat2,how2+'(tx)'
    ledger.append(dict(src='credicorp',date=d.isoformat(),name=src.title(),note=(tx+(' / '+concepto if concepto else '')),amount=round(ret,2),cat=cat,how=how,ref='CC-'+refn+'-'+d.strftime('%m%d'),pm=1))
ledger.sort(key=lambda e:(e['date'],e['src']))
json.dump(dict(ledger=ledger,review=[[str(x) for x in r] for r in review]),open('ledger_2026_h1.json','w'),indent=1)
if __name__=='__main__':
    byM=collections.defaultdict(lambda:[0,0.0,0]); unk=collections.Counter()
    for e in ledger:
        m=e['date'][:7]; byM[m][0]+=1; byM[m][1]+=e['amount']
        if e['cat'] is None: byM[m][2]+=1; unk[e['name']]+=1
    print('entries',len(ledger),'review',len(review))
    for m in sorted(byM): print(m,byM[m])
    print('unknown names:',len(unk)); print(unk.most_common(80))
    print('credicorp:'); [print(e['date'],e['note'][:55],e['amount'],e['cat'],e['how']) for e in ledger if e['src']=='credicorp']
    print('review:'); [print(r) for r in review]
