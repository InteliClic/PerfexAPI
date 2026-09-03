import re
CF=115
# (regex on lowercase name/note, category, clientid, tag)
OVERRIDES=[
 (r'\bwise\b',2,CF,'rule'),
 (r'canna|bud barn|original farm|kiaro|muse |rise mill|rise cannabis|seaside|elmwood|clarity|warmland|uem |cloud nine|west coast cones|1904|inspired',16,CF,'rule'),
 (r'airbnb|fairmont|westin|hotel|chalet|cox bay|beach club|resort|air can|helijet|hullo|bcf |bc ferries|uber|ubr|upe express|metrolinx|airport|aeropuerto|paybyphone|park',5,0,'rule'),
 (r'restaurant|restaur|pub\b|taphouse|bistro|kitchen|grill|sushi|pizzeria|pizza|steak|cactus club|earls|red robin|original joe|boston pizza|brazen fork|hemmingway|amicci|ricardo|jakes|tides|lakehouse|lobby bar|opa|noodlebox|spaghetti|mexican|chinese|japanese|thai|greek|cafe|caffe|coffee|starbucks|tim hortons|bakery|brunch|artigiano|drumroaster|olde school|hard bean|firewood|marilena|small victory|sol fine|vineyard|cidery|brewsk',15,0,'convention'),
 (r'.',17,0,'convention'),   # everything else that was Samples/Other -> Owner Advances
]
def override(name,note,cat):
    n=(name+' '+note).lower()
    if cat in (2,3,4,8,10,11,12,13,14) and 'wise' not in n: return None
    for rx,c,cl,tag in OVERRIDES:
        if re.search(rx,n): return c,cl,tag
    return None
