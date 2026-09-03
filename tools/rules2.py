import re
# secondary rules for names the 2025 map didn't cover: (regex on normalized name, category, tag)
RULES=[
 (r'^natiparra|natalia parra',2,'rule'),(r'^jlteel',None,'ASK'),
 (r'deployhq|windsurf|nano banana|voip|openai|anthropic|claude|github|cursor|vercel|cloudflare|zapier|notion|slack|zoom|adobe|microsoft|google|apple\.com|icloud',4,'rule'),
 (r'internetbs|networksolutions|epik|namecheap|godaddy|tld registrar|domain',11,'rule'),
 (r'serverpilot|hetzner|digitalocean|linode|aws|amazon web|hostworld|hosting',12,'rule'),
 (r'bc registr|prov of bc|registry|notary|lawyer|accountant',3,'rule'),
 (r'firewalla|bennettech|indoor farmer|ultra tec|kingsley north|amzn|amazon|canadian tire|princess auto|home depot|rona|lowes|best buy|dollarama|spicecraft|rain or shine',8,'rule'),
 (r'jlcpcb|pcbway|alibaba|aliexpress|alipay|digikey|mouser|lcsc',13,'rule'),
 (r'cannabis|farm|bud barn|canna',6,'convention'),
 (r'pizza|sushi|restaurant|pub|cafe|coffee|brunch|starbucks|tim hortons|a & w|a&w|subway|mcdonald|burger|dominos|domino\'s|panago|skipthedishes|boston pizza|donair|nook|fork|bar$|lobby bar|tides|hemmingway|amicci|brazen|artigiano|drumroaster|hard bean|island slice|moo\'s|sbux|foods|grocer|market|macs|convenience|7-eleven|shell|chevron|petro|esso|co op|co-op',6,'convention'),
 (r'mount washington|mt washington|ski|alpin|ticket|tix|famous player|cineplex|fortnine|motorsport|arcteryx|foot locker|browns|rexall|pharmacy|pet zone|pet|barbe|cidery|liquor',6,'convention'),
]
def rule_lookup(name):
    n=name.lower().strip()
    for rx,cat,tag in RULES:
        if re.search(rx,n): return cat,tag
    return None,'GUESS'
