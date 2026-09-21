<script lang="ts">
    import { page } from '@inertiajs/svelte';

    const copy = {
        en: {
            heading: 'What’s for dinner?',
            paragraphs: [
                'Handlelista is an app for people who live together. You pick the week’s dinners from your own recipes, and the app writes the shopping list – with the right amounts, added up across the dishes.',
                'Everyone in the household sees the same list. It also works when the store has no signal.',
            ],
            comingSoon: 'Coming soon to iPhone',
            listTitle: 'This week’s shopping list',
            listDinners: 'Bolognese, tomato soup, tacos',
            shoppingList: [
                { name: 'Tomatoes', amount: '6', isChecked: true },
                { name: 'Minced beef', amount: '400 g', isChecked: true },
                { name: 'Spaghetti', amount: '500 g', isChecked: false },
                { name: 'Onions', amount: '3', isChecked: false },
                { name: 'Cooking cream', amount: '3 dl', isChecked: false },
                { name: 'Tortillas', amount: '8', isChecked: false },
            ],
        },
        no: {
            heading: 'Hva skal vi ha til middag?',
            paragraphs: [
                'Handlelista er en app for dere som bor sammen. Dere velger ukas middager fra egne oppskrifter, og appen lager handlelista – med riktige mengder, slått sammen på tvers av rettene.',
                'Alle i husstanden ser den samme lista. Den virker også når butikken er uten dekning.',
            ],
            comingSoon: 'Kommer snart til iPhone',
            listTitle: 'Ukas handleliste',
            listDinners: 'Bolognese, tomatsuppe, taco',
            shoppingList: [
                { name: 'Tomater', amount: '6 stk', isChecked: true },
                { name: 'Kjøttdeig', amount: '400 g', isChecked: true },
                { name: 'Spaghetti', amount: '500 g', isChecked: false },
                { name: 'Løk', amount: '3 stk', isChecked: false },
                { name: 'Matfløte', amount: '3 dl', isChecked: false },
                { name: 'Tortillalefser', amount: '8 stk', isChecked: false },
            ],
        },
    };

    const t = $derived(copy[page.props.locale]);
</script>

<div
    class="my-auto grid items-center gap-14 md:grid-cols-[1fr_18rem] md:gap-16"
>
    <div>
        <h1
            class="text-4xl font-semibold tracking-tight text-balance sm:text-5xl"
        >
            {t.heading}
        </h1>
        <div
            class="mt-6 max-w-lg space-y-4 text-lg leading-8 text-muted-foreground"
        >
            {#each t.paragraphs as paragraph (paragraph)}
                <p>{paragraph}</p>
            {/each}
        </div>
        <p
            class="mt-8 inline-flex items-center gap-2 rounded-full border border-border bg-muted/40 px-4 py-2 text-sm font-medium"
        >
            <span
                class="size-2 rounded-full bg-blue-600 dark:bg-blue-400"
                aria-hidden="true"
            ></span>
            {t.comingSoon}
        </p>
    </div>

    <div
        aria-hidden="true"
        class="w-full max-w-72 -rotate-2 justify-self-center rounded-2xl border border-border bg-muted/40 p-6"
    >
        <p class="font-semibold">{t.listTitle}</p>
        <p class="mt-1 text-sm text-muted-foreground">
            {t.listDinners}
        </p>
        <ul class="mt-4 space-y-3">
            {#each t.shoppingList as item (item.name)}
                <li class="flex items-center gap-3">
                    <span
                        class={[
                            'flex size-5 shrink-0 items-center justify-center rounded-full border-2',
                            item.isChecked
                                ? 'border-blue-600 bg-blue-600 dark:border-blue-400 dark:bg-blue-400'
                                : 'border-foreground/25',
                        ]}
                    >
                        {#if item.isChecked}
                            <svg
                                viewBox="0 0 12 12"
                                class="size-3 fill-none stroke-background stroke-2"
                            >
                                <path
                                    d="M2.5 6.5l2.5 2.5 4.5-5.5"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                            </svg>
                        {/if}
                    </span>
                    <span
                        class={[
                            'grow',
                            item.isChecked && 'line-through opacity-50',
                        ]}
                    >
                        {item.name}
                    </span>
                    <span class="text-sm text-muted-foreground tabular-nums">
                        {item.amount}
                    </span>
                </li>
            {/each}
        </ul>
    </div>
</div>
