/**
 * Auth Helper - Complete CSRF Auto-Sync System
 * Version: 7.4.0 - GET BODY BUG FIXED
 */

const AuthHelper = (() => {
    const getApiBase = () => {
        const origin = window.location.origin;
        const path = window.location.pathname || '/';
        const dir = path.substring(0, path.lastIndexOf('/')) || '';
        if (dir === '' || dir === '/') {
            return origin + '/api/auth.php';
        }
        return origin + dir + '/api/auth.php';
    };
    const API_BASE = getApiBase();

    let _csrfToken = '';
    let _isInitialized = false;
    let _syncPromise = null;

    const storeUserData = (user) => {
        try {
            localStorage.setItem('user', JSON.stringify(user));
            localStorage.setItem('isLoggedIn', 'true');
            localStorage.setItem('userId', String(user.id || user.user_id || ''));
        } catch (e) {
            console.warn('[Auth] storeUserData error', e);
        }
    };

    const clearUserData = () => {
        try {
            localStorage.removeItem('user');
            localStorage.removeItem('isLoggedIn');
            localStorage.removeItem('userId');
            localStorage.removeItem('csrf_token');
            _csrfToken = '';
            _isInitialized = false;
            _syncPromise = null;
        } catch (e) {
            console.warn('[Auth] clearUserData error', e);
        }
    };

    const updateCsrfToken = (newToken) => {
        if (newToken && newToken.length > 10) {
            _csrfToken = newToken;
            localStorage.setItem('csrf_token', newToken);
            
            const meta = document.querySelector('meta[name="csrf-token"]');
            if (meta) {
                meta.setAttribute('content', newToken);
            }
            
            window.csrfToken = newToken;
            document.cookie = `XSRF-TOKEN=${newToken}; path=/; max-age=7200; samesite=Lax`;
            
            return true;
        }
        return false;
    };

    const syncCsrfToken = async (forceRefresh = false) => {
        if (_syncPromise && !forceRefresh) {
            return _syncPromise;
        }
        
        if (forceRefresh) {
            _csrfToken = '';
            localStorage.removeItem('csrf_token');
            const meta = document.querySelector('meta[name="csrf-token"]');
            if (meta) meta.setAttribute('content', '');
            window.csrfToken = '';
        }
        
        _syncPromise = (async () => {
            try {
                const resp = await fetch(`${API_BASE}?action=get_csrf`, {
                    method: 'GET',
                    credentials: 'include',
                    headers: { 
                        'Accept': 'application/json',
                        'Cache-Control': 'no-cache'
                    }
                });
                const data = await resp.json();
                
                if (data.success && data.data && data.data.csrf_token) {
                    updateCsrfToken(data.data.csrf_token);
                    _isInitialized = true;
                    return data.data.csrf_token;
                }
                return null;
            } catch (e) {
                console.warn('[CSRF] Sync error:', e);
                return null;
            } finally {
                setTimeout(() => { _syncPromise = null; }, 100);
            }
        })();
        
        return _syncPromise;
    };

    const getCsrfToken = async (forceRefresh = false) => {
        if (forceRefresh) {
            return await syncCsrfToken(true);
        }
        
        if (_csrfToken && _csrfToken.length > 10) return _csrfToken;
        
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.getAttribute('content') && meta.getAttribute('content').length > 10) {
            _csrfToken = meta.getAttribute('content');
            localStorage.setItem('csrf_token', _csrfToken);
            window.csrfToken = _csrfToken;
            return _csrfToken;
        }
        
        const cached = localStorage.getItem('csrf_token');
        if (cached && cached.length > 10) { 
            _csrfToken = cached; 
            window.csrfToken = cached;
            return _csrfToken; 
        }

        const synced = await syncCsrfToken();
        if (synced) return synced;
        
        _csrfToken = 'csrf_' + Date.now() + '_' + Math.random().toString(36).substring(2, 15);
        localStorage.setItem('csrf_token', _csrfToken);
        return _csrfToken;
    };

    const refreshCsrfToken = async () => {
        return await syncCsrfToken(true);
    };

    // ==============================================
    // 🔥 REQUEST FUNCTION - COMPLETELY FIXED
    // ==============================================
    const request = async (url, method = 'POST', body = {}, retryCount = 0) => {
        try {
            const csrf = await getCsrfToken();
            
            // ✅ STEP 1: Options object banao (bina body ke)
            const options = {
                method: method,
                credentials: 'include',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrf || ''
                }
            };
            
            // ✅ STEP 2: Sirf POST/PUT/PATCH/DELETE mein body add karo
            // GET aur HEAD requests mein body NAHI hoti
            if (method === 'POST' || method === 'PUT' || method === 'PATCH' || method === 'DELETE') {
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify({
                    ...body,
                    csrf_token: csrf || ''
                });
            }
            
            // ✅ STEP 3: Fetch karo
            const response = await fetch(url, options);
            const data = await response.json();
            
            // ✅ STEP 4: Token update karo
            if (data.data && data.data.csrf_token) {
                updateCsrfToken(data.data.csrf_token);
            } else if (data.csrf_token) {
                updateCsrfToken(data.csrf_token);
            }
            
            // ✅ STEP 5: CSRF fail par retry
            if (!data.success && data.data && data.data.refresh_needed && data.data.csrf_token) {
                if (retryCount < 2) {
                    updateCsrfToken(data.data.csrf_token);
                    await new Promise(resolve => setTimeout(resolve, 300));
                    return await request(url, method, body, retryCount + 1);
                }
            }
            
            return data;
        } catch (e) {
            console.error('[Request] Error:', e);
            return { success: false, message: 'Network error. Please check your connection.' };
        }
    };

    const register = async ({ username, mobile, password, email = '', referral_code = '' }) => {
        try {
            if (!username || username.length < 3) return { success: false, message: 'Username must be at least 3 characters' };
            if (!mobile || !/^[0-9]{10}$/.test(mobile)) return { success: false, message: 'Please enter a valid 10-digit mobile number' };
            if (!password || password.length < 6) return { success: false, message: 'Password must be at least 6 characters' };

            const result = await request(`${API_BASE}?action=register`, 'POST', {
                username: username.trim(),
                mobile: mobile.trim(),
                email: email.trim() || '',
                password: password,
                referral_code: referral_code?.trim() || ''
            });
            
            if (result.success && result.data && result.data.user) {
                storeUserData(result.data.user);
                if (result.data.csrf_token) {
                    updateCsrfToken(result.data.csrf_token);
                }
            }
            
            return result;
        } catch (err) {
            console.error('[Auth] register error', err);
            return { success: false, message: 'Network error. Please try again.' };
        }
    };

    const login = async ({ username, password }) => {
        try {
            if (!username || !password) return { success: false, message: 'Username and password are required' };
            
            const result = await request(`${API_BASE}?action=login`, 'POST', { 
                username: username.trim(), 
                password 
            });
            
            if (result.success && result.data && result.data.user) {
                storeUserData(result.data.user);
                if (result.data.csrf_token) {
                    updateCsrfToken(result.data.csrf_token);
                }
            }
            
            return result;
        } catch (err) {
            console.error('[Auth] login error', err);
            return { success: false, message: 'Network error. Please try again.' };
        }
    };

    const logout = async () => {
        try {
            const csrf = await getCsrfToken();
            await fetch(`${API_BASE}?action=logout`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrf || ''
                },
                body: JSON.stringify({ csrf_token: csrf || '' })
            }).catch(() => {});
        } catch (e) {
            console.warn('[Auth] logout fetch failed', e);
        } finally {
            clearUserData();
            window.csrfToken = '';
            return { success: true };
        }
    };

    const checkAuth = async () => {
        try {
            const storedUser = localStorage.getItem('user');
            const isLoggedIn = localStorage.getItem('isLoggedIn');
            if (isLoggedIn === 'true' && storedUser) {
                try {
                    const u = JSON.parse(storedUser);
                    return { success: true, isLoggedIn: true, user: u };
                } catch (e) { clearUserData(); }
            }

            const csrf = await getCsrfToken();
            const resp = await fetch(`${API_BASE}?action=check`, {
                method: 'GET',
                credentials: 'include',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrf || ''
                }
            });
            const data = await resp.json();
            
            if (data.data && data.data.csrf_token) {
                updateCsrfToken(data.data.csrf_token);
            } else if (data.csrf_token) {
                updateCsrfToken(data.csrf_token);
            }

            if (data.success && data.data && data.data.logged_in === true && data.data.user) {
                storeUserData(data.data.user);
                return { success: true, isLoggedIn: true, user: data.data.user };
            }
            
            clearUserData();
            return { success: true, isLoggedIn: false };
        } catch (err) {
            console.warn('[Auth] checkAuth error', err);
            return { success: false, isLoggedIn: false, message: 'Network error' };
        }
    };

    return {
        register,
        login,
        logout,
        checkAuth,
        getCsrfToken,
        updateCsrfToken,
        refreshCsrfToken,
        syncCsrfToken,
        request,
        API_BASE
    };
})();

window.AuthHelper = AuthHelper;
window.csrfToken = localStorage.getItem('csrf_token') || '';

document.addEventListener('DOMContentLoaded', async function() {
    try {
        const freshToken = await AuthHelper.syncCsrfToken();
        if (freshToken) {
            console.log('[CSRF] ✅ Token synced');
        }
    } catch (e) {
        console.warn('[CSRF] Init error:', e);
    }
});