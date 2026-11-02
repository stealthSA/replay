<?php
declare(strict_types=1);
/**
 * Topic lexicon used by the trend model. Edit freely: add brands and keywords for your market.
 * 'brands'     => display name => [spellings that count as a mention]
 * 'categories' => category      => [keywords / phrases]
 * Advertisers' own keywords are matched on top of this, so this list only needs to cover common ground.
 */
return [
    'brands' => [
        'Nike' => ['nike', 'air max', 'air force 1', 'airmax', 'jordan 1', 'air jordan'], 'Adidas' => ['adidas', 'yeezy', 'samba', 'gazelle'], 'Puma' => ['puma'], 'New Balance' => ['new balance', 'newbalance'],
        'Converse' => ['converse'], 'Vans' => ['vans'], 'Asics' => ['asics'], 'Under Armour' => ['under armour', 'underarmour'], 'Reebok' => ['reebok'],
        'RCP' => ['rcp', 'rcp exhaust', 'rcp exhausts'], 'Akrapovic' => ['akrapovic', 'akrapovič'], 'Milltek' => ['milltek'], 'Remus' => ['remus'], 'Brembo' => ['brembo'], 'Pirelli' => ['pirelli'],
        'BMW' => ['bmw', 'm3', 'm4'], 'Volkswagen' => ['volkswagen', 'vw', 'golf gti', 'polo gti'], 'Toyota' => ['toyota', 'hilux', 'gr86', 'supra'], 'Ford' => ['ford', 'ranger raptor'], 'Mercedes-Benz' => ['mercedes', 'amg'], 'Audi' => ['audi'],
        'Samsung' => ['samsung', 'galaxy'], 'Apple' => ['iphone', 'macbook', 'airpods', 'apple watch'], 'Huawei' => ['huawei'], 'Google' => ['pixel 9', 'pixel 10', 'google pixel'], 'Sony' => ['sony', 'playstation', 'ps5'], 'Xbox' => ['xbox'], 'Nintendo' => ['nintendo', 'switch 2'],
        'Takealot' => ['takealot'], 'Woolworths' => ['woolworths'], 'Checkers' => ['checkers', 'sixty60'], 'Pick n Pay' => ['pick n pay', 'picknpay'], 'Nando\'s' => ['nandos', 'nando\'s', 'peri-peri', 'peri peri'], 'Steers' => ['steers'], 'KFC' => ['kfc'], 'McDonald\'s' => ['mcdonalds', 'mcdonald\'s'],
        'Red Bull' => ['red bull', 'redbull'], 'Coca-Cola' => ['coca-cola', 'coca cola', 'coke zero'], 'Spotify' => ['spotify'], 'Netflix' => ['netflix', 'showmax'], 'Uber' => ['uber', 'bolt ride'], 'Vodacom' => ['vodacom'], 'MTN' => ['mtn'], 'Capitec' => ['capitec'],
        'Glossier' => ['glossier'], 'Fenty Beauty' => ['fenty'], 'Dis-Chem' => ['dis-chem', 'dischem'], 'Clicks' => ['clicks'], 'Garmin' => ['garmin'], 'GoPro' => ['gopro'], 'DJI' => ['dji'],
    ],
    'categories' => [
        'sneakers'   => ['sneaker', 'sneakers', 'kicks', 'trainers', 'sneakerhead', 'sneakerheads', 'colorway', 'drop day', 'unboxing kicks', 'running shoes', 'footwear'],
        'automotive' => ['exhaust', 'exhausts', 'turbo', 'car', 'cars', 'engine', 'tuning', 'tuned', 'mods', 'rims', 'supercar', 'gti', 'drift', 'dyno', 'revs', 'rev limiter', 'v8', 'track day', 'carmeet', 'car meet', 'bakkie', 'suv'],
        'food'       => ['recipe', 'recipes', 'braai', 'pasta', 'burger', 'dessert', 'baking', 'cooking', 'foodie', 'restaurant', 'street food', 'coffee', 'brunch', 'lunch', 'dinner'],
        'fashion'    => ['outfit', 'ootd', 'streetwear', 'fashion', 'style', 'thrift', 'wardrobe', 'lookbook', 'fit check', 'dress', 'jacket', 'denim'],
        'beauty'     => ['skincare', 'makeup', 'hair', 'braids', 'lashes', 'nails', 'serum', 'moisturiser', 'moisturizer', 'foundation', 'beauty'],
        'tech'       => ['phone', 'smartphone', 'laptop', 'gadget', 'ai', 'app', 'software', 'review', 'unboxing', 'camera', 'headphones', 'earbuds', 'wifi', 'fibre'],
        'gaming'     => ['gaming', 'gamer', 'esports', 'playstation', 'xbox', 'fortnite', 'minecraft', 'speedrun', 'streamer', 'twitch'],
        'fitness'    => ['gym', 'workout', 'fitness', 'running', 'marathon', 'cardio', 'protein', 'training', 'yoga', 'crossfit', 'cycling'],
        'music'      => ['song', 'album', 'single', 'concert', 'festival', 'afrobeats', 'dj set', 'live set', 'playlist', 'gig', 'lyrics'],
        'amapiano'   => ['amapiano', 'piano', 'log drum', 'kabza', 'kabza de small', 'maphorisa', 'dj maphorisa', 'major league djz', 'major league', 'uncle waffles', 'focalistic', 'young stunna', 'daliwonga', 'tyler icu', 'tyla', 'dbn gogo', 'sha sha', 'mellow and sleazy', 'sir trill', 'vigro deep', 'felo le tee', 'scorpion kings', 'soa mattrix', 'bontle modiselle'],
        'hiphop'     => ['hip hop', 'hiphop', 'hip-hop', 'rapper', 'rappers', 'rap', 'freestyle', 'cypher', 'kendrick', 'kendrick lamar', 'drake', 'travis scott', 'j cole', '21 savage', 'cardi b', 'nicki minaj', 'lil wayne', 'future', 'metro boomin', 'nas', 'jay-z', 'jay z', 'eminem', 'tyler the creator', 'playboi carti', 'sa hip hop', 'cassper nyovest', 'a-reece', 'nasty c', 'emtee', 'k.o', 'kiernan forbes'],
        'travel'     => ['travel', 'trip', 'road trip', 'holiday', 'hotel', 'beach', 'safari', 'hike', 'hiking', 'getaway', 'flight', 'itinerary'],
        'motorsport' => ['formula 1', 'formula1', 'f1', 'grand prix', 'verstappen', 'hamilton', 'norris', 'leclerc', 'ferrari', 'red bull racing', 'mclaren', 'qualifying', 'pit stop', 'motogp', 'rally', 'nascar'],
        'entertainment' => ['movie', 'movies', 'film', 'trailer', 'netflix', 'series', 'tv show', 'box office', 'oscars', 'celebrity', 'premiere', 'season finale', 'streaming', 'actor', 'actress', 'hollywood', 'bollywood', 'nollywood'],
        'sport'      => ['nba', 'basketball', 'tennis', 'golf', 'boxing', 'ufc', 'premier league', 'champions league', 'highlights', 'transfer', 'world cup', 'nfl', 'soccer', 'football', 'rugby', 'cricket', 'springboks', 'bafana', 'psl', 'match day', 'goal', 'tournament', 'olympics'],
        'home'       => ['furniture', 'decor', 'interior', 'renovation', 'diy', 'garden', 'kitchen', 'apartment', 'home office'],
        'finance'    => ['savings', 'investing', 'budget', 'side hustle', 'crypto', 'loan', 'credit score', 'tax', 'startup'],
        'pets'       => ['dog', 'puppy', 'cat', 'kitten', 'pet', 'rescue', 'adopt'],
    ],
    'stop' => ['the', 'and', 'for', 'you', 'your', 'with', 'this', 'that', 'have', 'from', 'are', 'was', 'were', 'but', 'not', 'all', 'can', 'will', 'just', 'about', 'what', 'when', 'where', 'who', 'how', 'why', 'out', 'get', 'got', 'its', 'our', 'their', 'them', 'they', 'then', 'than', 'there', 'here', 'been', 'being', 'has', 'had', 'did', 'does', 'doing', 'more', 'most', 'some', 'any', 'one', 'two', 'new', 'now', 'today', 'tonight', 'link', 'bio', 'comments', 'comment', 'like', 'follow', 'share', 'watch', 'check', 'see', 'look', 'really', 'very', 'much', 'many', 'into', 'over', 'back', 'also', 'too', 'let', 'lets', 'make', 'made', 'come', 'time', 'day', 'good', 'great', 'best', 'love', 'amazing', 'know', 'think', 'want', 'need', 'dont', 'cant', 'wont', 'only', 'even', 'still', 'again', 'first', 'last', 'next', 'every', 'would', 'could', 'should', 'because', 'after', 'before', 'while', 'these', 'those', 'such', 'other', 'another', 'around', 'always', 'never', 'thing', 'things', 'people', 'everyone', 'someone', 'something', 'anyone', 'week', 'weekend', 'year', 'right', 'down', 'off', 'put', 'say', 'said', 'take', 'took', 'going', 'gone', 'yes', 'done', 'real'],
];
