<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import {
    Coffee,
    ShoppingBag,
    Plus,
    Minus,
    Trash2,
    CheckCircle2,
    Sparkles,
    Clock,
    Utensils,
    Search,
    ChevronRight,
    X,
    ReceiptText,
    Store,
    RotateCcw,
    ChefHat,
} from 'lucide-vue-next';

interface MenuItem {
    id: number;
    name: string;
    price: number | string;
    is_available: boolean;
    description?: string;
    category?: string;
}

interface TableData {
    id: string;
    table_number: string;
    current_token?: string | null;
}

interface CartItem {
    menu_id: number;
    name: string;
    price: number;
    quantity: number;
    notes: string;
}

interface CheckoutResponse {
    status: string;
    message: string;
    data: {
        id: string;
        table_id: string;
        total_price: string | number;
        status: string;
        created_at: string;
        items: Array<{
            id: number;
            menu_id: number;
            quantity: number;
            notes: string | null;
            menu: {
                id: number;
                name: string;
                price: string | number;
            };
        }>;
    };
}

const props = defineProps<{
    table: TableData;
    menus: MenuItem[];
}>();

// Helper untuk memperkaya menu dengan deskripsi & kategori estetis jika belum ada di database
const getMenuDetails = (name: string) => {
    const lower = name.toLowerCase();
    if (
        lower.includes('croissant') ||
        lower.includes('pastry') ||
        lower.includes('fries')
    ) {
        return {
            category: 'Pastry & Bites',
            badge: 'Pastry',
            desc: lower.includes('croissant')
                ? 'Pastry renyah berlapis khas Perancis dengan butter aromatic.'
                : lower.includes('beef')
                  ? 'Pastry gurih berisikan smoked beef lezat dan lelehan keju.'
                  : 'Kentang goreng renyah beraroma truffle dan parmesan.',
        };
    }
    if (lower.includes('matcha')) {
        return {
            category: 'Non-Coffee',
            badge: 'Tea Latte',
            desc: 'Matcha murni dari Kyoto berpadu dengan susu segar lembut.',
        };
    }
    return {
        category: 'Coffee',
        badge: 'Signature',
        desc: lower.includes('aren')
            ? 'Espresso blend aromatik dengan susu segar dan gula aren murni.'
            : lower.includes('espresso')
              ? 'Ekstraksi murni biji Arabika pilihan dengan crema tebal.'
              : lower.includes('caramel')
                ? 'Espresso lembut dengan vanilla milk dan sirup karamel gurih.'
                : 'Double shot espresso dingin yang clean, bold, dan segar.',
    };
};

// State
const searchQuery = ref('');
const selectedCategory = ref('Semua');
const cart = ref<CartItem[]>([]);
const isCartOpen = ref(false);
const isSubmitting = ref(false);
const checkoutSuccess = ref(false);
const lastOrder = ref<CheckoutResponse['data'] | null>(null);
const errorMessage = ref<string | null>(null);

// Categories
const categories = ['Semua', 'Coffee', 'Non-Coffee', 'Pastry & Bites'];

// Format Rupiah
const formatRupiah = (val: number | string) => {
    const num = typeof val === 'string' ? parseFloat(val) : val;
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(num || 0);
};

// Filtered Menus
const filteredMenus = computed(() => {
    return props.menus.filter((menu) => {
        const details = getMenuDetails(menu.name);
        const matchCat =
            selectedCategory.value === 'Semua' ||
            details.category === selectedCategory.value;
        const matchSearch =
            menu.name.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
            details.desc
                .toLowerCase()
                .includes(searchQuery.value.toLowerCase());
        return matchCat && matchSearch;
    });
});

// Cart Computations
const totalItems = computed(() => {
    return cart.value.reduce((sum, item) => sum + item.quantity, 0);
});

const totalPrice = computed(() => {
    return cart.value.reduce(
        (sum, item) => sum + item.price * item.quantity,
        0,
    );
});

// Cart Actions
const getItemQuantityInCart = (menuId: number): number => {
    const found = cart.value.find((i) => i.menu_id === menuId);
    return found ? found.quantity : 0;
};

const addToCart = (menu: MenuItem) => {
    const numPrice =
        typeof menu.price === 'string' ? parseFloat(menu.price) : menu.price;
    const existing = cart.value.find((i) => i.menu_id === menu.id);
    if (existing) {
        existing.quantity += 1;
    } else {
        cart.value.push({
            menu_id: menu.id,
            name: menu.name,
            price: numPrice,
            quantity: 1,
            notes: '',
        });
    }
};

const decreaseQuantity = (menuId: number) => {
    const idx = cart.value.findIndex((i) => i.menu_id === menuId);
    if (idx !== -1) {
        if (cart.value[idx].quantity > 1) {
            cart.value[idx].quantity -= 1;
        } else {
            cart.value.splice(idx, 1);
        }
    }
};

const removeFromCart = (menuId: number) => {
    const idx = cart.value.findIndex((i) => i.menu_id === menuId);
    if (idx !== -1) {
        cart.value.splice(idx, 1);
    }
};

// Checkout Function
const handleCheckout = async () => {
    if (cart.value.length === 0 || isSubmitting.value) return;

    isSubmitting.value = true;
    errorMessage.value = null;

    const payload = {
        table_id: props.table.id,
        total_price: totalPrice.value,
        items: cart.value.map((item) => ({
            menu_id: item.menu_id,
            quantity: item.quantity,
            notes: item.notes.trim() || null,
        })),
    };

    try {
        const response = await axios.post<CheckoutResponse>(
            '/api/orders/checkout',
            payload,
            {
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                },
            },
        );

        if (response.status === 201 || response.status === 200) {
            lastOrder.value = response.data.data;
            checkoutSuccess.value = true;
            isCartOpen.value = false;
            cart.value = []; // Kosongkan keranjang setelah checkout sukses
        }
    } catch (err: any) {
        console.error('Checkout error:', err);
        if (err.response?.data?.message) {
            errorMessage.value = err.response.data.message;
        } else {
            errorMessage.value =
                'Terjadi kendala saat mengirim pesanan. Silakan periksa koneksi internet Anda.';
        }
    } finally {
        isSubmitting.value = false;
    }
};

const resetOrderState = () => {
    checkoutSuccess.value = false;
    lastOrder.value = null;
    errorMessage.value = null;
};
</script>

<template>
    <Head title="E-Menu & Pemesanan | KopiKita" />

    <div
        class="flex min-h-screen justify-center bg-stone-100 text-stone-800 selection:bg-amber-500 selection:text-white"
    >
        <!-- Main Mobile-First Wrapper (Max Width: 520px) -->
        <main
            class="relative flex min-h-screen w-full max-w-lg flex-col bg-white pb-28 shadow-2xl"
        >
            <!-- 1. STICKY HEADER -->
            <header
                class="sticky top-0 z-40 border-b border-stone-800/80 bg-stone-900/95 px-4 py-3.5 text-white shadow-md backdrop-blur-md"
            >
                <div class="flex items-center justify-between">
                    <!-- Brand / Logo -->
                    <div class="flex items-center gap-2.5">
                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-tr from-amber-600 to-amber-400 shadow-lg ring-2 shadow-amber-600/30 ring-amber-500/20"
                        >
                            <Coffee
                                class="h-5 w-5 stroke-[2.3] text-stone-950"
                            />
                        </div>
                        <div>
                            <h1
                                class="flex items-center gap-1.5 font-serif text-lg leading-tight font-bold tracking-tight"
                            >
                                KopiKita
                                <span
                                    class="inline-block h-1.5 w-1.5 rounded-full bg-amber-400"
                                ></span>
                            </h1>
                            <p
                                class="font-sans text-[10px] tracking-wider text-stone-400 uppercase"
                            >
                                Artisan Coffee & Eatery
                            </p>
                        </div>
                    </div>

                    <!-- Meja Info & Cart Quick Toggle -->
                    <div class="flex items-center gap-2">
                        <!-- Badge Meja -->
                        <div
                            class="flex items-center gap-1.5 rounded-full border border-amber-500/40 bg-amber-950/70 px-3 py-1 text-xs font-semibold text-amber-300 shadow-inner"
                        >
                            <span class="relative flex h-2 w-2">
                                <span
                                    class="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75"
                                ></span>
                                <span
                                    class="relative inline-flex h-2 w-2 rounded-full bg-amber-500"
                                ></span>
                            </span>
                            <span>{{ props.table.table_number }}</span>
                        </div>

                        <!-- Header Cart Icon Button -->
                        <button
                            @click="isCartOpen = true"
                            aria-label="Buka Keranjang"
                            class="relative rounded-full bg-stone-800/80 p-2 text-stone-200 transition-colors hover:bg-stone-700"
                        >
                            <ShoppingBag class="h-4 w-4" />
                            <span
                                v-if="totalItems > 0"
                                class="absolute -top-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-amber-500 text-[10px] font-extrabold text-stone-950 shadow-sm"
                            >
                                {{ totalItems }}
                            </span>
                        </button>
                    </div>
                </div>
            </header>

            <!-- 2. HERO GREETING BANNER -->
            <section
                class="relative overflow-hidden rounded-b-[2rem] bg-gradient-to-b from-stone-900 via-stone-900 to-stone-800 px-5 pt-4 pb-6 text-white shadow-lg"
            >
                <div
                    class="pointer-events-none absolute -right-12 -bottom-12 h-40 w-40 rounded-full bg-amber-500/10 blur-2xl"
                ></div>
                <div class="relative z-10">
                    <div
                        class="mb-2 inline-flex items-center gap-1 rounded-full border border-amber-400/25 bg-amber-500/15 px-2.5 py-0.5 text-xs font-medium text-amber-300"
                    >
                        <Sparkles class="h-3 w-3" />
                        <span>Self-Order E-Menu</span>
                    </div>
                    <h2 class="font-serif text-xl leading-snug font-bold">
                        Nikmati Seduhan Hari Ini di
                        {{ props.table.table_number }}
                    </h2>
                    <p class="mt-1 text-xs leading-relaxed text-stone-300">
                        Pesan langsung dari genggaman Anda. Barista kami siap
                        meracik secangkir kehangatan untuk Anda.
                    </p>
                </div>
            </section>

            <!-- 3. SEARCH & CATEGORY FILTER -->
            <section class="z-20 -mt-3 px-4">
                <!-- Search Input -->
                <div class="relative rounded-2xl shadow-md">
                    <Search
                        class="absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-stone-400"
                    />
                    <input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Cari kopi, pastry, atau cemilan..."
                        class="w-full rounded-2xl border border-stone-200 bg-white py-2.5 pr-4 pl-10 text-xs text-stone-800 shadow-sm transition-all placeholder:text-stone-400 focus:border-amber-500 focus:ring-2 focus:ring-amber-500/40 focus:outline-none"
                    />
                    <button
                        v-if="searchQuery"
                        @click="searchQuery = ''"
                        class="absolute top-1/2 right-3 -translate-y-1/2 text-stone-400 hover:text-stone-600"
                    >
                        <X class="h-3.5 w-3.5" />
                    </button>
                </div>

                <!-- Category Pills -->
                <div
                    class="no-scrollbar flex items-center gap-2 overflow-x-auto py-3"
                >
                    <button
                        v-for="cat in categories"
                        :key="cat"
                        @click="selectedCategory = cat"
                        :class="[
                            'cursor-pointer rounded-full px-3.5 py-1.5 text-xs font-medium whitespace-nowrap transition-all',
                            selectedCategory === cat
                                ? 'bg-amber-800 text-white shadow-sm ring-1 shadow-amber-900/20 ring-amber-800'
                                : 'bg-stone-100 text-stone-600 hover:bg-stone-200 hover:text-stone-800',
                        ]"
                    >
                        {{ cat }}
                    </button>
                </div>
            </section>

            <!-- 4. MENU CATALOG GRID -->
            <section class="mt-2 flex-1 px-4">
                <div class="mb-3 flex items-center justify-between">
                    <h3
                        class="flex items-center gap-1.5 font-serif text-sm font-bold tracking-tight text-stone-900"
                    >
                        <Utensils class="h-4 w-4 text-amber-700" />
                        Daftar Menu
                    </h3>
                    <span class="text-[11px] font-medium text-stone-500">
                        {{ filteredMenus.length }} menu ditemukan
                    </span>
                </div>

                <!-- Empty State -->
                <div
                    v-if="filteredMenus.length === 0"
                    class="my-6 rounded-2xl border border-amber-100 bg-amber-50/50 p-8 text-center"
                >
                    <Coffee
                        class="mx-auto mb-2 h-10 w-10 stroke-1 text-amber-400"
                    />
                    <p class="text-xs font-semibold text-stone-700">
                        Menu tidak ditemukan
                    </p>
                    <p class="mt-0.5 text-[11px] text-stone-500">
                        Coba kata kunci lain atau pilih kategori "Semua".
                    </p>
                    <button
                        @click="
                            searchQuery = '';
                            selectedCategory = 'Semua';
                        "
                        class="mt-3 text-xs font-semibold text-amber-700 underline underline-offset-2"
                    >
                        Reset Pencarian
                    </button>
                </div>

                <!-- Menu Cards List -->
                <div class="space-y-3">
                    <div
                        v-for="menu in filteredMenus"
                        :key="menu.id"
                        class="group relative flex items-center gap-3.5 overflow-hidden rounded-2xl border border-stone-200/90 bg-white p-3.5 shadow-sm transition-all hover:shadow-md"
                    >
                        <!-- Visual Icon / Badge Thumbnail -->
                        <div
                            class="flex h-20 w-20 shrink-0 flex-col items-center justify-center rounded-xl border border-amber-200/60 bg-gradient-to-br from-amber-100/80 via-orange-50 to-stone-100 text-amber-800 shadow-inner transition-transform duration-300 group-hover:scale-105"
                        >
                            <Coffee
                                v-if="
                                    getMenuDetails(menu.name).category ===
                                    'Coffee'
                                "
                                class="h-7 w-7 stroke-[1.75]"
                            />
                            <Utensils v-else class="h-7 w-7 stroke-[1.75]" />
                            <span
                                class="mt-1 text-[9px] font-bold tracking-wider uppercase opacity-80"
                            >
                                {{ getMenuDetails(menu.name).badge }}
                            </span>
                        </div>

                        <!-- Information -->
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-1">
                                <h4
                                    class="truncate text-sm leading-tight font-bold text-stone-900 transition-colors group-hover:text-amber-800"
                                >
                                    {{ menu.name }}
                                </h4>
                            </div>

                            <p
                                class="mt-1 line-clamp-2 text-[11px] leading-snug text-stone-500"
                            >
                                {{ getMenuDetails(menu.name).desc }}
                            </p>

                            <div
                                class="mt-2.5 flex items-center justify-between border-t border-stone-100 pt-1"
                            >
                                <!-- Price -->
                                <span
                                    class="text-sm font-extrabold text-amber-800"
                                >
                                    {{ formatRupiah(menu.price) }}
                                </span>

                                <!-- Action: Add / Counter Button -->
                                <div>
                                    <!-- Jika belum ada di keranjang -->
                                    <button
                                        v-if="
                                            getItemQuantityInCart(menu.id) === 0
                                        "
                                        @click="addToCart(menu)"
                                        :disabled="!menu.is_available"
                                        class="inline-flex cursor-pointer items-center gap-1 rounded-xl bg-amber-800 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-all hover:bg-amber-900 hover:shadow active:scale-95 disabled:opacity-50"
                                    >
                                        <Plus
                                            class="h-3.5 w-3.5 stroke-[2.5]"
                                        />
                                        <span>Tambah</span>
                                    </button>

                                    <!-- Jika sudah ada di keranjang: Tampilkan Counter -->
                                    <div
                                        v-else
                                        class="inline-flex items-center gap-2 rounded-xl bg-stone-900 px-1.5 py-1 text-xs text-white shadow-sm ring-1 ring-stone-800"
                                    >
                                        <button
                                            @click="decreaseQuantity(menu.id)"
                                            class="flex h-5 w-5 items-center justify-center rounded-lg bg-stone-800 text-amber-400 transition hover:bg-stone-700 active:scale-90"
                                            aria-label="Kurangi"
                                        >
                                            <Minus class="h-3 w-3 stroke-[3]" />
                                        </button>
                                        <span
                                            class="min-w-4 text-center text-xs font-bold text-amber-200"
                                        >
                                            {{ getItemQuantityInCart(menu.id) }}
                                        </span>
                                        <button
                                            @click="addToCart(menu)"
                                            class="flex h-5 w-5 items-center justify-center rounded-lg bg-stone-800 text-amber-400 transition hover:bg-stone-700 active:scale-90"
                                            aria-label="Tambah"
                                        >
                                            <Plus class="h-3 w-3 stroke-[3]" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 5. STICKY FLOATING BOTTOM BAR (Muncul jika ada item di keranjang) -->
            <transition
                enter-active-class="transition duration-300 ease-out"
                enter-from-class="transform translate-y-20 opacity-0"
                enter-to-class="transform translate-y-0 opacity-100"
                leave-active-class="transition duration-200 ease-in"
                leave-from-class="transform translate-y-0 opacity-100"
                leave-to-class="transform translate-y-20 opacity-0"
            >
                <div
                    v-if="totalItems > 0 && !checkoutSuccess"
                    class="pointer-events-none fixed right-0 bottom-3 left-0 z-40 flex justify-center px-4"
                >
                    <div
                        class="pointer-events-auto flex w-full max-w-md items-center justify-between rounded-2xl border border-stone-800 bg-stone-950 p-3 text-white shadow-2xl shadow-stone-950/40 backdrop-blur-md"
                    >
                        <div class="flex items-center gap-3 pl-1">
                            <div class="relative">
                                <div
                                    class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500 font-bold text-stone-950"
                                >
                                    <ShoppingBag class="h-5 w-5" />
                                </div>
                                <span
                                    class="absolute -top-1.5 -right-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-amber-300 text-[10px] font-black text-stone-950 ring-2 ring-stone-950"
                                >
                                    {{ totalItems }}
                                </span>
                            </div>
                            <div>
                                <p
                                    class="text-[10px] font-semibold tracking-wider text-stone-400 uppercase"
                                >
                                    Total Pesanan
                                </p>
                                <p
                                    class="text-base font-extrabold tracking-tight text-amber-300"
                                >
                                    {{ formatRupiah(totalPrice) }}
                                </p>
                            </div>
                        </div>

                        <button
                            @click="isCartOpen = true"
                            class="flex cursor-pointer items-center gap-1.5 rounded-xl bg-amber-600 px-4 py-2.5 text-xs font-bold text-stone-950 shadow-md shadow-amber-600/30 transition hover:bg-amber-500 active:scale-95"
                        >
                            <span>Lihat Pesanan</span>
                            <ChevronRight class="h-4 w-4 stroke-[2.5]" />
                        </button>
                    </div>
                </div>
            </transition>

            <!-- 6. CART DRAWER / BOTTOM SHEET MODAL -->
            <transition
                enter-active-class="transition-opacity ease-out duration-300"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition-opacity ease-in duration-200"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="isCartOpen"
                    class="fixed inset-0 z-50 flex items-end justify-center bg-stone-950/70 backdrop-blur-xs sm:items-center"
                    @click.self="isCartOpen = false"
                >
                    <div
                        class="animate-in slide-in-from-bottom flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl duration-300 sm:rounded-3xl"
                    >
                        <!-- Drawer Header -->
                        <div
                            class="flex items-center justify-between border-b border-stone-200 bg-stone-50 px-5 py-4"
                        >
                            <div>
                                <h3
                                    class="flex items-center gap-2 font-serif text-base font-bold text-stone-900"
                                >
                                    <ShoppingBag
                                        class="h-4 w-4 text-amber-700"
                                    />
                                    Keranjang Belanja
                                </h3>
                                <p class="text-xs text-stone-500">
                                    Antar ke:
                                    <strong
                                        class="font-semibold text-amber-800"
                                        >{{ props.table.table_number }}</strong
                                    >
                                </p>
                            </div>
                            <button
                                @click="isCartOpen = false"
                                class="rounded-full bg-stone-200/80 p-1.5 text-stone-600 transition hover:bg-stone-300"
                            >
                                <X class="h-4 w-4" />
                            </button>
                        </div>

                        <!-- Error Banner (jika ada) -->
                        <div
                            v-if="errorMessage"
                            class="mx-4 mt-3 rounded-xl border border-red-200 bg-red-50 px-3.5 py-2.5 text-xs text-red-700"
                        >
                            {{ errorMessage }}
                        </div>

                        <!-- Items List -->
                        <div
                            class="space-y-3.5 divide-y divide-stone-100 overflow-y-auto p-4"
                        >
                            <div
                                v-for="item in cart"
                                :key="item.menu_id"
                                class="pt-3.5 first:pt-0"
                            >
                                <div
                                    class="flex items-start justify-between gap-2"
                                >
                                    <div>
                                        <h4
                                            class="text-xs leading-tight font-bold text-stone-900"
                                        >
                                            {{ item.name }}
                                        </h4>
                                        <p
                                            class="mt-0.5 text-xs font-semibold text-amber-800"
                                        >
                                            {{ formatRupiah(item.price) }}
                                        </p>
                                    </div>

                                    <!-- Counter in Cart -->
                                    <div
                                        class="flex items-center gap-2 rounded-xl bg-stone-100 px-2 py-1"
                                    >
                                        <button
                                            @click="
                                                decreaseQuantity(item.menu_id)
                                            "
                                            class="flex h-5 w-5 items-center justify-center rounded-lg bg-white text-stone-700 shadow-xs transition hover:text-red-600 active:scale-90"
                                        >
                                            <Minus class="h-3 w-3" />
                                        </button>
                                        <span
                                            class="min-w-4 text-center text-xs font-bold"
                                        >
                                            {{ item.quantity }}
                                        </span>
                                        <button
                                            @click="
                                                addToCart({
                                                    id: item.menu_id,
                                                    name: item.name,
                                                    price: item.price,
                                                    is_available: true,
                                                })
                                            "
                                            class="flex h-5 w-5 items-center justify-center rounded-lg bg-white text-stone-700 shadow-xs transition hover:text-amber-800 active:scale-90"
                                        >
                                            <Plus class="h-3 w-3" />
                                        </button>
                                    </div>
                                </div>

                                <!-- Input Notes per Item -->
                                <div class="mt-2">
                                    <input
                                        v-model="item.notes"
                                        type="text"
                                        placeholder="Catatan: Cth: less ice, gula dipisah, ekstra panas..."
                                        class="w-full rounded-lg border border-stone-200 bg-stone-50 px-3 py-1.5 text-[11px] text-stone-700 placeholder:text-stone-400 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 focus:outline-none"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- Drawer Footer / Checkout Button -->
                        <div
                            class="space-y-3 border-t border-stone-200 bg-stone-50 p-4"
                        >
                            <!-- Ringkasan Harga -->
                            <div class="space-y-1.5 text-xs">
                                <div
                                    class="flex justify-between text-stone-500"
                                >
                                    <span>Total Item</span>
                                    <span class="font-medium text-stone-800"
                                        >{{ totalItems }} porsi</span
                                    >
                                </div>
                                <div
                                    class="flex justify-between text-stone-500"
                                >
                                    <span>Pajak Restoran</span>
                                    <span class="font-medium text-stone-800"
                                        >Sudah Termasuk</span
                                    >
                                </div>
                                <div
                                    class="flex justify-between border-t border-stone-200 pt-1.5 text-sm font-bold text-stone-900"
                                >
                                    <span>Total Pembayaran</span>
                                    <span
                                        class="text-base font-extrabold text-amber-800"
                                        >{{ formatRupiah(totalPrice) }}</span
                                    >
                                </div>
                            </div>

                            <!-- Big Submit Button -->
                            <button
                                @click="handleCheckout"
                                :disabled="isSubmitting || cart.length === 0"
                                class="flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-amber-700 to-amber-600 px-4 py-3.5 text-sm font-bold text-white shadow-lg shadow-amber-800/30 transition-all hover:from-amber-800 hover:to-amber-700 active:scale-[0.99] disabled:opacity-50"
                            >
                                <span
                                    v-if="isSubmitting"
                                    class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white"
                                ></span>
                                <ChefHat v-else class="h-4 w-4" />
                                <span>{{
                                    isSubmitting
                                        ? 'Meneruskan ke Dapur...'
                                        : 'Pesan Sekarang'
                                }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </transition>

            <!-- 7. SUCCESS MODAL (Barista Preparation & Animated Confirmation) -->
            <transition
                enter-active-class="transition duration-300 ease-out"
                enter-from-class="opacity-0 scale-95"
                enter-to-class="opacity-100 scale-100"
                leave-active-class="transition duration-200 ease-in"
                leave-from-class="opacity-100 scale-100"
                leave-to-class="opacity-0 scale-95"
            >
                <div
                    v-if="checkoutSuccess"
                    class="fixed inset-0 z-50 flex items-center justify-center bg-stone-950/85 p-4 backdrop-blur-sm"
                >
                    <div
                        class="relative w-full max-w-sm overflow-hidden rounded-3xl border border-amber-100 bg-white p-6 text-center shadow-2xl"
                    >
                        <!-- Decorative top glow -->
                        <div
                            class="absolute top-0 right-0 left-0 h-2 bg-gradient-to-r from-amber-500 to-orange-400"
                        ></div>

                        <!-- Animated Icon Badge -->
                        <div
                            class="relative mx-auto my-3 flex h-20 w-20 items-center justify-center"
                        >
                            <div
                                class="absolute inset-0 animate-ping rounded-full bg-amber-100 opacity-75"
                            ></div>
                            <div
                                class="relative flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-tr from-amber-600 to-amber-500 text-white shadow-xl ring-4 shadow-amber-600/40 ring-amber-100"
                            >
                                <CheckCircle2 class="h-9 w-9 stroke-[2.2]" />
                            </div>
                        </div>

                        <!-- Success Headings -->
                        <div
                            class="mb-2 inline-flex items-center gap-1.5 rounded-full bg-green-50 px-3 py-1 text-xs font-semibold text-green-700"
                        >
                            <Clock class="h-3.5 w-3.5" />
                            <span>Pesanan Diterima di Dapur</span>
                        </div>

                        <h3
                            class="font-serif text-xl leading-tight font-bold text-stone-900"
                        >
                            Pesanan Anda Berhasil Dikirim!
                        </h3>

                        <p
                            class="mt-2 px-2 text-xs leading-relaxed text-stone-600"
                        >
                            Barista kami sedang menyiapkan pesanan Anda dengan
                            penuh ketelitian. Silakan duduk santai di
                            <strong class="font-bold text-amber-800">{{
                                props.table.table_number
                            }}</strong
                            >.
                        </p>

                        <!-- Order Summary Card -->
                        <div
                            v-if="lastOrder"
                            class="mt-4 space-y-1.5 rounded-2xl border border-stone-100 bg-stone-50 p-3 text-left text-xs"
                        >
                            <div class="flex justify-between text-stone-500">
                                <span>No. Order (ID):</span>
                                <span
                                    class="font-mono text-[10px] text-stone-700"
                                    >{{ lastOrder.id.slice(0, 8) }}...</span
                                >
                            </div>
                            <div class="flex justify-between text-stone-500">
                                <span>Total Bayar:</span>
                                <span class="font-bold text-amber-800">{{
                                    formatRupiah(lastOrder.total_price)
                                }}</span>
                            </div>
                            <div class="flex justify-between text-stone-500">
                                <span>Status:</span>
                                <span
                                    class="font-semibold text-amber-700 capitalize"
                                    >Cooking / Sedang Diracik</span
                                >
                            </div>
                        </div>

                        <!-- Close / Order More Button -->
                        <button
                            @click="resetOrderState"
                            class="mt-5 flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl bg-stone-900 px-4 py-3 text-xs font-bold text-white shadow-md transition hover:bg-stone-800 active:scale-95"
                        >
                            <RotateCcw class="h-3.5 w-3.5" />
                            <span>Pesan Menu Tambahan</span>
                        </button>
                    </div>
                </div>
            </transition>
        </main>
    </div>
</template>

<style scoped>
/* Utility untuk menyembunyikan scrollbar pada category list */
.no-scrollbar::-webkit-scrollbar {
    display: none;
}
.no-scrollbar {
    -ms-overflow-style: none;
    scrollbar-width: none;
}
</style>
