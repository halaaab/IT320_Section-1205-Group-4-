// ═══════════════════════════════════════════════════════════════════════
// RePlate — fetch() snippets for every HTML page
// Drop these into the <script> section of each HTML file
// Replace your existing alert('Account created successfully!') calls
// ═══════════════════════════════════════════════════════════════════════

// ─────────────────────────────────────────────
// SHARED — signup-customer.html  (line 444)
// Replace: if (valid) { alert('Account created successfully!'); }
// ─────────────────────────────────────────────
if (valid) {
    const res    = await fetch('../../back-end/api/auth/signup-customer.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({
            name:     name.value.trim(),
            email:    email.value.trim(),
            phone:    document.getElementById('phone').value.trim(),
            password: password.value,
        })
    });
    const result = await res.json();
    if (result.success) {
        window.location.href = 'login.html';
    } else {
        document.getElementById('emailError').textContent = result.message;
        document.getElementById('emailError').classList.add('show');
    }
}

// ─────────────────────────────────────────────
// SHARED — singup-provider.html
// ─────────────────────────────────────────────
if (valid) {
    const res    = await fetch('../../back-end/api/auth/signup-provider.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({
            businessName:        document.getElementById('businessName').value.trim(),
            email:               document.getElementById('email').value.trim(),
            phone:               document.getElementById('phone').value.trim(),
            password:            document.getElementById('password').value,
            businessDescription: document.getElementById('description').value.trim(),
            category:            document.getElementById('category').value,
            street:              document.getElementById('street').value.trim(),
            city:                document.getElementById('city').value.trim(),
        })
    });
    const result = await res.json();
    if (result.success) {
        window.location.href = 'login.html';
    } else {
        showError('emailError', result.message);
    }
}

// ─────────────────────────────────────────────
// SHARED — login.html
// ─────────────────────────────────────────────
async function handleLogin() {
    const email    = document.getElementById('email');
    const password = document.getElementById('password');
    const role     = document.getElementById('role')?.value || 'customer'; // toggle customer/provider

    if (!email.value || !password.value) return;

    const res    = await fetch('../../back-end/api/auth/login.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ email: email.value.trim(), password: password.value, role })
    });
    const result = await res.json();

    if (result.success) {
        // Save to localStorage for JS to use across pages
        localStorage.setItem('userId',   result.data.customerId || result.data.providerId);
        localStorage.setItem('userName', result.data.fullName   || result.data.businessName);
        localStorage.setItem('role',     result.data.role);

        // Redirect based on role
        if (result.data.role === 'provider') {
            window.location.href = '../provider/provider-dashboard.html';
        } else {
            window.location.href = '../customer/category.html';
        }
    } else {
        document.getElementById('loginError').textContent = result.message;
        document.getElementById('loginError').classList.add('show');
    }
}

// ─────────────────────────────────────────────
// SHARED — landing.html  (on page load)
// ─────────────────────────────────────────────
async function loadHome() {
    const res    = await fetch('../../back-end/api/shared/home.php');
    const result = await res.json();
    if (!result.success) return;

    const { categories, items } = result.data;
    // Render categories to your category grid
    renderCategories(categories);
    // Render featured items
    renderItems(items);
}
document.addEventListener('DOMContentLoaded', loadHome);

// ─────────────────────────────────────────────
// CUSTOMER — category.html  (on page load)
// ─────────────────────────────────────────────
async function loadCategory() {
    const params     = new URLSearchParams(window.location.search);
    const categoryId = params.get('categoryId');
    const type       = params.get('type') || 'all';

    const res    = await fetch(`../../back-end/api/customer/category.php?categoryId=${categoryId}&type=${type}`);
    const result = await res.json();
    if (result.success) renderItems(result.data.items);
}
document.addEventListener('DOMContentLoaded', loadCategory);

// ─────────────────────────────────────────────
// CUSTOMER — providers-list.html  (on page load)
// ─────────────────────────────────────────────
async function loadProviders() {
    const res    = await fetch('../../back-end/api/customer/providers.php');
    const result = await res.json();
    if (result.success) renderProviders(result.data.providers);
}
document.addEventListener('DOMContentLoaded', loadProviders);

// ─────────────────────────────────────────────
// CUSTOMER — providers-page.html  (on page load)
// ─────────────────────────────────────────────
async function loadProviderPage() {
    const params     = new URLSearchParams(window.location.search);
    const providerId = params.get('providerId');

    const res    = await fetch(`../../back-end/api/customer/providers.php?providerId=${providerId}`);
    const result = await res.json();
    if (result.success) {
        renderProviderHeader(result.data.provider);
        renderItems(result.data.items);
    }
}
document.addEventListener('DOMContentLoaded', loadProviderPage);

// ─────────────────────────────────────────────
// CUSTOMER — item-details.html  (on page load)
// ─────────────────────────────────────────────
async function loadItemDetails() {
    const params = new URLSearchParams(window.location.search);
    const itemId = params.get('itemId');

    const res    = await fetch(`../../back-end/api/customer/item-details.php?itemId=${itemId}`);
    const result = await res.json();
    if (result.success) {
        const { item, provider, location, category } = result.data;
        renderItemDetail(item, provider, location, category);
    }
}
document.addEventListener('DOMContentLoaded', loadItemDetails);

// Add to cart button
async function addToCart(itemId, providerId, itemName, price, quantity) {
    const res    = await fetch('../../back-end/api/customer/cart.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ itemId, providerId, itemName, price, quantity })
    });
    const result = await res.json();
    if (result.success) showToast('Added to cart! 🛒');
    else if (result.message.includes('Unauthorized')) window.location.href = '../shared/login.html';
}

// Toggle favourite button
async function toggleFavourite(itemId, isSaved) {
    const method = isSaved ? 'DELETE' : 'POST';
    const res    = await fetch('../../back-end/api/customer/favourites.php', {
        method,
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ itemId })
    });
    const result = await res.json();
    if (result.success) showToast(isSaved ? 'Removed from favourites' : 'Saved! ❤️');
}

// ─────────────────────────────────────────────
// CUSTOMER — cart.html  (on page load)
// ─────────────────────────────────────────────
async function loadCart() {
    const res    = await fetch('../../back-end/api/customer/cart.php');
    const result = await res.json();
    if (result.success) renderCart(result.data.cart);
}

async function updateCartItem(itemId, quantity) {
    await fetch('../../back-end/api/customer/cart.php', {
        method:  'PUT',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ itemId, quantity })
    });
    loadCart(); // refresh
}

async function removeCartItem(itemId) {
    await fetch('../../back-end/api/customer/cart.php', {
        method:  'DELETE',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ itemId })
    });
    loadCart(); // refresh
}
document.addEventListener('DOMContentLoaded', loadCart);

// ─────────────────────────────────────────────
// CUSTOMER — checkout.html
// ─────────────────────────────────────────────
async function placeOrder() {
    const res    = await fetch('../../back-end/api/customer/checkout.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({
            selectedPickupTime: document.getElementById('pickupTime')?.value || '',
        })
    });
    const result = await res.json();
    if (result.success) {
        window.location.href = `order-details.html?orderId=${result.data.orderId}`;
    } else {
        showError('checkoutError', result.message);
    }
}

// ─────────────────────────────────────────────
// CUSTOMER — orders.html  (on page load)
// ─────────────────────────────────────────────
async function loadOrders() {
    const res    = await fetch('../../back-end/api/customer/orders.php');
    const result = await res.json();
    if (result.success) renderOrders(result.data.orders);
}
document.addEventListener('DOMContentLoaded', loadOrders);

// ─────────────────────────────────────────────
// CUSTOMER — order-details.html  (on page load)
// ─────────────────────────────────────────────
async function loadOrderDetails() {
    const params  = new URLSearchParams(window.location.search);
    const orderId = params.get('orderId');

    const res    = await fetch(`../../back-end/api/customer/orders.php?orderId=${orderId}`);
    const result = await res.json();
    if (result.success) renderOrderDetail(result.data.order, result.data.items);
}

async function cancelOrder(orderId) {
    const res    = await fetch('../../back-end/api/customer/orders.php', {
        method:  'DELETE',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ orderId })
    });
    const result = await res.json();
    if (result.success) loadOrderDetails(); // refresh page
    else showError('cancelError', result.message);
}
document.addEventListener('DOMContentLoaded', loadOrderDetails);

// ─────────────────────────────────────────────
// CUSTOMER — favorites.html  (on page load)
// ─────────────────────────────────────────────
async function loadFavourites() {
    const res    = await fetch('../../back-end/api/customer/favourites.php');
    const result = await res.json();
    if (result.success) renderFavourites(result.data.favourites);
}
document.addEventListener('DOMContentLoaded', loadFavourites);

// ─────────────────────────────────────────────
// CUSTOMER — customer-profile.html  (on page load)
// ─────────────────────────────────────────────
async function loadProfile() {
    const res    = await fetch('../../back-end/api/customer/customer-profile.php');
    const result = await res.json();
    if (result.success) populateProfileForm(result.data.customer);
}

async function saveProfile() {
    const res    = await fetch('../../back-end/api/customer/customer-profile.php', {
        method:  'PUT',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({
            fullName:    document.getElementById('fullName').value.trim(),
            phoneNumber: document.getElementById('phone').value.trim(),
        })
    });
    const result = await res.json();
    if (result.success) showToast('Profile saved ✅');
}
document.addEventListener('DOMContentLoaded', loadProfile);

// ─────────────────────────────────────────────
// CUSTOMER — contact.html
// ─────────────────────────────────────────────
async function submitTicket() {
    const res    = await fetch('../../back-end/api/customer/contact.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({
            reason:      document.getElementById('reason').value,
            description: document.getElementById('description').value.trim(),
        })
    });
    const result = await res.json();
    if (result.success) showToast('Ticket submitted! We\'ll be in touch 💬');
    else showError('contactError', result.message);
}

// ─────────────────────────────────────────────
// PROVIDER — provider-dashboard.html  (on page load)
// ─────────────────────────────────────────────
async function loadDashboard() {
    const res    = await fetch('../../back-end/api/provider/provider-dashboard.php');
    const result = await res.json();
    if (result.success) {
        const { stats, expiringSoon, recentOrders } = result.data;
        renderStats(stats);
        renderExpiringSoon(expiringSoon);
        renderRecentOrders(recentOrders);
    }
}
document.addEventListener('DOMContentLoaded', loadDashboard);

// ─────────────────────────────────────────────
// PROVIDER — provider-items.html  (on page load)
// ─────────────────────────────────────────────
async function loadProviderItems() {
    const res    = await fetch('../../back-end/api/provider/provider-items.php');
    const result = await res.json();
    if (result.success) renderProviderItems(result.data.items);
}

async function toggleAvailability(itemId, currentStatus) {
    await fetch('../../back-end/api/provider/provider-items.php', {
        method:  'PUT',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ itemId, isAvailable: !currentStatus })
    });
    loadProviderItems(); // refresh
}

async function deleteItem(itemId) {
    if (!confirm('Delete this item?')) return;
    const res    = await fetch('../../back-end/api/provider/provider-items.php', {
        method:  'DELETE',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ itemId })
    });
    const result = await res.json();
    if (result.success) loadProviderItems();
}
document.addEventListener('DOMContentLoaded', loadProviderItems);

// ─────────────────────────────────────────────
// PROVIDER — provider-item-details.html  (on page load)
// ─────────────────────────────────────────────
async function loadProviderItemDetails() {
    const params = new URLSearchParams(window.location.search);
    const itemId = params.get('itemId');

    const res    = await fetch(`../../back-end/api/provider/provider-items.php?itemId=${itemId}`);
    const result = await res.json();
    if (result.success) renderProviderItemDetail(result.data.item, result.data.location, result.data.category);
}
document.addEventListener('DOMContentLoaded', loadProviderItemDetails);

// ─────────────────────────────────────────────
// PROVIDER — provider-add-item.html  (on page load + submit)
// ─────────────────────────────────────────────
async function loadAddItemDropdowns() {
    const res    = await fetch('../../back-end/api/provider/provider-add-item.php');
    const result = await res.json();
    if (!result.success) return;

    // Populate category dropdown
    const catSelect = document.getElementById('category');
    result.data.categories.forEach(c => {
        catSelect.innerHTML += `<option value="${c._id}">${c.name}</option>`;
    });

    // Populate pickup location dropdown
    const locSelect = document.getElementById('pickupLocation');
    result.data.locations.forEach(l => {
        locSelect.innerHTML += `<option value="${l._id}">${l.label} — ${l.street}</option>`;
    });
}

async function submitAddItem() {
    const pickupTimes = [...document.querySelectorAll('.pickup-time:checked')]
                          .map(el => el.value);
    const res    = await fetch('../../back-end/api/provider/provider-add-item.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({
            categoryId:       document.getElementById('category').value,
            pickupLocationId: document.getElementById('pickupLocation').value,
            itemName:         document.getElementById('itemName').value.trim(),
            description:      document.getElementById('description').value.trim(),
            photoUrl:         document.getElementById('photoUrl').value.trim(),
            expiryDate:       document.getElementById('expiryDate').value,
            listingType:      document.getElementById('listingType').value,
            price:            document.getElementById('price').value || 0,
            quantity:         document.getElementById('quantity').value,
            pickupTimes,
        })
    });
    const result = await res.json();
    if (result.success) {
        showToast('Item added! ✅');
        window.location.href = 'provider-items.html';
    } else {
        showError('addItemError', result.message);
    }
}
document.addEventListener('DOMContentLoaded', loadAddItemDropdowns);

// ─────────────────────────────────────────────
// PROVIDER — provider-orders.html  (on page load)
// ─────────────────────────────────────────────
async function loadProviderOrders() {
    const res    = await fetch('../../back-end/api/provider/provider-orders.php');
    const result = await res.json();
    if (result.success) renderProviderOrders(result.data.orders);
}

async function markComplete(orderId) {
    const res    = await fetch('../../back-end/api/provider/provider-orders.php', {
        method:  'PUT',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ orderId })
    });
    const result = await res.json();
    if (result.success) loadProviderOrders();
}
document.addEventListener('DOMContentLoaded', loadProviderOrders);

// ─────────────────────────────────────────────
// PROVIDER — provider-order-details.html  (on page load)
// ─────────────────────────────────────────────
async function loadProviderOrderDetails() {
    const params  = new URLSearchParams(window.location.search);
    const orderId = params.get('orderId');

    const res    = await fetch(`../../back-end/api/provider/provider-orders.php?orderId=${orderId}`);
    const result = await res.json();
    if (result.success) renderProviderOrderDetail(result.data.order, result.data.items);
}
document.addEventListener('DOMContentLoaded', loadProviderOrderDetails);

// ─────────────────────────────────────────────
// PROVIDER — provider-profile.html  (on page load)
// ─────────────────────────────────────────────
async function loadProviderProfile() {
    const res    = await fetch('../../back-end/api/provider/provider-profile.php');
    const result = await res.json();
    if (result.success) {
        populateProviderForm(result.data.provider);
        renderLocations(result.data.locations);
    }
}

async function saveProviderProfile() {
    const res    = await fetch('../../back-end/api/provider/provider-profile.php', {
        method:  'PUT',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({
            businessName:        document.getElementById('businessName').value.trim(),
            phoneNumber:         document.getElementById('phone').value.trim(),
            businessDescription: document.getElementById('description').value.trim(),
        })
    });
    const result = await res.json();
    if (result.success) showToast('Profile saved ✅');
}
document.addEventListener('DOMContentLoaded', loadProviderProfile);

// ─────────────────────────────────────────────
// ALL PAGES — notification bell (shared)
// ─────────────────────────────────────────────
async function loadNotifications() {
    const res    = await fetch('../../back-end/api/shared/notifications.php');
    const result = await res.json();
    if (!result.success) return;

    const { notifications, unreadCount } = result.data;
    document.getElementById('notifBadge').textContent = unreadCount || '';
    renderNotifications(notifications);
}

async function markAllNotificationsRead() {
    await fetch('../../back-end/api/shared/notifications.php', {
        method:  'PUT',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({})
    });
    loadNotifications();
}

// ─────────────────────────────────────────────
// ALL PAGES — logout button (shared)
// ─────────────────────────────────────────────
async function logout() {
    await fetch('../../back-end/api/auth/logout.php', { method: 'POST' });
    localStorage.clear();
    window.location.href = '../../front-end/shared/login.html';
}

// ─────────────────────────────────────────────
// UTILITY — reusable helpers
// ─────────────────────────────────────────────
function showToast(message) {
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

function showError(elementId, message) {
    const el = document.getElementById(elementId);
    if (el) { el.textContent = message; el.classList.add('show'); }
}
