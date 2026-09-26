<?php

namespace App\Actions\Ai;

/**
 * Everyday dinners Norwegian households actually cook. Each week-planning prompt
 * gets a random handful that fits the preferences and season, so suggestions stay
 * recognisable family food without every week looking the same.
 */
class DinnerIdeas
{
    public const SHORTCUTS = ['quick', 'budget', 'vegetarian', 'kids', 'fish', 'healthy', 'traditional', 'weekend', 'one_pot', 'low_carb'];

    /**
     * [Norwegian name, English name, shortcuts it suits, months it is in season (all year when omitted)].
     *
     * @var list<array{0: string, 1: string, 2: list<string>, 3?: list<int>}>
     */
    private const IDEAS = [
        // Fish and seafood
        ['Fiskegrateng', 'Fish gratin', ['fish', 'kids', 'traditional', 'budget']],
        ['Fiskekaker med kokte poteter og gulrøtter', 'Fish cakes with boiled potatoes and carrots', ['fish', 'kids', 'traditional', 'budget', 'quick']],
        ['Ovnsbakt laks med poteter og brokkoli', 'Oven-baked salmon with potatoes and broccoli', ['fish', 'healthy', 'quick', 'kids']],
        ['Torsk med poteter, gulrøtter og smeltet smør', 'Cod with potatoes, carrots and melted butter', ['fish', 'traditional', 'healthy']],
        ['Fiskesuppe', 'Norwegian fish soup', ['fish', 'traditional', 'one_pot']],
        ['Fiskepinner med potetmos og erter', 'Fish fingers with mashed potatoes and peas', ['fish', 'kids', 'quick', 'budget']],
        ['Fisketaco med torsk', 'Cod fish tacos', ['fish', 'weekend', 'kids', 'quick']],
        ['Pasta med laks og spinat', 'Pasta with salmon and spinach', ['fish', 'quick', 'kids']],
        ['Seibiff med løk og poteter', 'Pan-fried saithe with onions and potatoes', ['fish', 'traditional', 'budget']],
        ['Plukkfisk', 'Creamy fish and potato mash', ['fish', 'traditional', 'budget', 'one_pot']],
        ['Fiskeboller i hvit saus med poteter', 'Fish balls in white sauce with potatoes', ['fish', 'traditional', 'budget', 'kids']],
        ['Teriyakilaks med ris og brokkoli', 'Teriyaki salmon with rice and broccoli', ['fish', 'quick', 'healthy']],
        ['Bacalao', 'Bacalao (salt cod stew)', ['fish', 'traditional', 'one_pot']],
        ['Laks og grønnsaker i langpanne', 'Salmon and vegetable traybake', ['fish', 'healthy', 'low_carb', 'one_pot', 'quick']],
        ['Pasta med scampi og hvitløk', 'Garlic prawn pasta', ['fish', 'quick']],
        ['Fiskeburger', 'Fish burgers', ['fish', 'weekend', 'kids']],
        ['Laksesalat med avokado og egg', 'Salmon salad with avocado and egg', ['fish', 'healthy', 'low_carb', 'quick']],

        // Chicken
        ['Kyllinggryte med ris', 'Chicken casserole with rice', ['kids', 'one_pot']],
        ['Kylling i karri med ris', 'Chicken curry with rice', ['kids', 'budget', 'one_pot']],
        ['Kylling tikka masala med ris', 'Chicken tikka masala with rice', ['kids']],
        ['Butter chicken med ris og naan', 'Butter chicken with rice and naan', ['kids', 'weekend']],
        ['Kyllingwok med nudler', 'Chicken stir-fry with noodles', ['quick', 'healthy', 'kids']],
        ['Ovnsbakte kyllinglår med poteter og grønnsaker', 'Roast chicken thighs with potatoes and vegetables', ['one_pot', 'budget', 'kids']],
        ['Kremet kyllingpasta', 'Creamy chicken pasta', ['quick', 'kids']],
        ['Kyllingfajitas', 'Chicken fajitas', ['weekend', 'kids', 'quick']],
        ['Cæsarsalat med kylling', 'Chicken Caesar salad', ['quick', 'low_carb']],
        ['Kyllingsuppe med grønnsaker', 'Chicken and vegetable soup', ['healthy', 'one_pot', 'budget']],
        ['Kyllingburger', 'Chicken burgers', ['weekend', 'kids']],
        ['Wraps med kylling og grønnsaker', 'Chicken and vegetable wraps', ['quick', 'kids']],
        ['Pita med kylling', 'Chicken pitas', ['quick', 'kids', 'weekend']],
        ['Grillet kylling med potetsalat', 'Grilled chicken with potato salad', ['kids', 'weekend'], [5, 6, 7, 8]],

        // Minced meat
        ['Taco', 'Tacos', ['weekend', 'kids', 'quick']],
        ['Spaghetti bolognese', 'Spaghetti Bolognese', ['kids', 'budget']],
        ['Lasagne', 'Lasagne', ['kids', 'weekend']],
        ['Kjøttkaker i brun saus med potetmos og ertestuing', 'Norwegian meatcakes in brown gravy with mash and creamed peas', ['traditional', 'kids']],
        ['Kjøttboller i tomatsaus med pasta', 'Meatballs in tomato sauce with pasta', ['kids', 'budget']],
        ['Chili con carne', 'Chili con carne', ['one_pot', 'budget']],
        ['Hjemmelagde hamburgere', 'Homemade burgers', ['weekend', 'kids']],
        ['Karbonader med stekt løk og poteter', 'Beef patties with fried onions and potatoes', ['traditional', 'budget', 'quick']],
        ['Tacogryte', 'Taco casserole', ['one_pot', 'quick', 'kids']],
        ['Pastagrateng med kjøttdeig', 'Minced beef pasta bake', ['kids', 'budget']],
        ['Tacosalat', 'Taco salad', ['quick', 'low_carb']],
        ['Kålruletter', 'Norwegian cabbage rolls', ['traditional', 'budget'], [9, 10, 11, 12, 1, 2, 3]],

        // Pork and sausages
        ['Pølser med potetmos', 'Sausages with mashed potatoes', ['kids', 'quick', 'budget']],
        ['Pølsegryte', 'Sausage stew', ['budget', 'one_pot', 'kids', 'quick']],
        ['Pytt i panne med speilegg', 'Norwegian hash with fried eggs', ['quick', 'budget', 'kids']],
        ['Pasta carbonara', 'Pasta carbonara', ['quick', 'kids']],
        ['Svinekoteletter med poteter og grønnsaker', 'Pork chops with potatoes and vegetables', ['traditional', 'quick']],
        ['Svinefilet med fløtegratinerte poteter', 'Pork tenderloin with potato gratin', ['weekend']],
        ['Sursøt svin med ris', 'Sweet and sour pork with rice', ['kids']],
        ['Lapskaus', 'Lapskaus (meat and potato stew)', ['traditional', 'one_pot', 'budget'], [9, 10, 11, 12, 1, 2, 3, 4]],
        ['Medisterkaker med surkål og poteter', 'Pork patties with sauerkraut and potatoes', ['traditional'], [10, 11, 12, 1, 2]],
        ['Ertesuppe med flesk', 'Yellow pea soup with bacon', ['traditional', 'budget', 'one_pot'], [10, 11, 12, 1, 2, 3]],

        // Beef, lamb and game
        ['Fårikål', 'Fårikål (lamb and cabbage stew)', ['traditional', 'one_pot'], [9, 10]],
        ['Biff stroganoff med ris', 'Beef stroganoff with rice', ['quick', 'kids']],
        ['Biff med bearnaisesaus og ovnspoteter', 'Steak with béarnaise and roast potatoes', ['weekend']],
        ['Wok med storfekjøtt og grønnsaker', 'Beef and vegetable stir-fry', ['quick', 'healthy', 'low_carb']],
        ['Kjøttsuppe med rotgrønnsaker', 'Beef and root vegetable soup', ['traditional', 'one_pot', 'healthy'], [10, 11, 12, 1, 2, 3]],
        ['Viltgryte med tyttebær og poteter', 'Game stew with lingonberries and potatoes', ['traditional', 'weekend', 'one_pot'], [9, 10, 11, 12]],
        ['Finnbiff med potetmos og tyttebær', 'Sautéed reindeer with mash and lingonberries', ['traditional', 'weekend'], [10, 11, 12, 1, 2, 3]],

        // Vegetarian
        ['Tomatsuppe med egg og makaroni', 'Tomato soup with egg and macaroni', ['vegetarian', 'budget', 'kids', 'quick', 'traditional']],
        ['Pannekaker med blåbærsyltetøy', 'Norwegian pancakes with blueberry jam', ['vegetarian', 'kids', 'budget', 'traditional']],
        ['Risgrøt', 'Rice porridge', ['vegetarian', 'budget', 'kids', 'traditional', 'weekend']],
        ['Linsesuppe', 'Lentil soup', ['vegetarian', 'budget', 'healthy', 'one_pot']],
        ['Vegetartaco med bønner', 'Bean tacos', ['vegetarian', 'weekend', 'kids', 'quick', 'budget']],
        ['Grønnsakslasagne', 'Vegetable lasagne', ['vegetarian', 'kids']],
        ['Kikertcurry med ris', 'Chickpea curry with rice', ['vegetarian', 'budget', 'quick', 'healthy', 'one_pot']],
        ['Omelett med grønnsaker', 'Vegetable omelette', ['vegetarian', 'quick', 'budget', 'low_carb']],
        ['Pasta med tomatsaus', 'Pasta with tomato sauce', ['vegetarian', 'quick', 'budget', 'kids']],
        ['Soppristotto', 'Mushroom risotto', ['vegetarian']],
        ['Falafel i pita', 'Falafel in pita', ['vegetarian', 'weekend', 'quick']],
        ['Ovnsbakte grønnsaker med halloumi', 'Roasted vegetables with halloumi', ['vegetarian', 'low_carb', 'healthy', 'one_pot']],
        ['Shakshuka', 'Shakshuka', ['vegetarian', 'quick', 'low_carb', 'budget', 'one_pot']],
        ['Quesadillas med bønner og ost', 'Bean and cheese quesadillas', ['vegetarian', 'quick', 'kids']],
        ['Nudelwok med tofu', 'Tofu noodle stir-fry', ['vegetarian', 'quick', 'healthy']],
        ['Blomkålsuppe', 'Cauliflower soup', ['vegetarian', 'low_carb', 'healthy', 'budget']],
        ['Bønneburger', 'Bean burgers', ['vegetarian', 'weekend', 'kids']],
        ['Brokkolipai', 'Broccoli and cheese quiche', ['vegetarian', 'kids']],
        ['Stekt ris med egg og grønnsaker', 'Egg fried rice with vegetables', ['vegetarian', 'quick', 'budget', 'kids']],

        // Friday favourites whose category depends on the toppings
        ['Hjemmelaget pizza', 'Homemade pizza', ['weekend', 'kids']],
    ];

    /**
     * A shuffled handful in the requested language. Vegetarian is a hard filter;
     * the other shortcuts fill about two thirds of the handful with matching ideas.
     *
     * @param  list<string>  $shortcuts
     * @param  list<string>  $exclude  Dinner names the household already has or turned down.
     * @return list<string>
     */
    public function sample(string $locale, array $shortcuts, array $exclude, int $month, int $count = 14): array
    {
        $excluded = array_fill_keys(array_map(fn (string $name) => mb_strtolower(trim($name)), $exclude), true);
        $vegetarian = in_array('vegetarian', $shortcuts, true);
        $wanted = array_values(array_diff($shortcuts, ['vegetarian']));
        $matching = [];
        $others = [];
        foreach (self::IDEAS as $idea) {
            [$nb, $en, $tags] = $idea;
            if (($vegetarian && ! in_array('vegetarian', $tags, true))
                || (isset($idea[3]) && ! in_array($month, $idea[3], true))
                || isset($excluded[mb_strtolower($nb)]) || isset($excluded[mb_strtolower($en)])) {
                continue;
            }
            $name = $locale === 'nb' ? $nb : $en;
            if (array_intersect($wanted, $tags) !== []) {
                $matching[] = $name;
            } else {
                $others[] = $name;
            }
        }
        shuffle($matching);
        shuffle($others);
        $preferred = $wanted === [] ? 0 : (int) ceil($count * 2 / 3);
        $picked = array_slice($matching, 0, $preferred);

        return array_slice([...$picked, ...array_slice($others, 0, $count - count($picked)), ...array_slice($matching, $preferred)], 0, $count);
    }
}
