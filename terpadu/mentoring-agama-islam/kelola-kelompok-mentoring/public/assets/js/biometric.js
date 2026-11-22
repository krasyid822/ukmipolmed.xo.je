/**
 * public/assets/js/biometric.js — Klien Verifikasi Biometrik & Pola 3x3
 * Digunakan oleh setup-biometric.php (pendaftaran) dan index.php (verifikasi
 * sebelum mengakses formulir input laporan).
 *
 * WebAuthn memakai credential "discoverable" (resident key) sehingga saat
 * verifikasi perangkat dapat memilih kredensialnya sendiri tanpa perlu
 * membocorkan credential_id lewat API publik. Server memverifikasi tanda
 * tangan asli (ES256) terhadap public key yang disimpan saat pendaftaran.
 */

function b64urlEncode(buffer) {
    const bytes = buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.byteLength; i++) {
        binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function b64urlDecode(value) {
    const normalized = value.replace(/-/g, '+').replace(/_/g, '/');
    const padded = normalized + '='.repeat((4 - (normalized.length % 4)) % 4);
    const binary = atob(padded);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }
    return bytes;
}

/**
 * Parser CBOR minimal untuk membaca attestationObject & COSE_Key dari WebAuthn.
 * Mendukung unsigned int, negative int, byte string, text string, array, dan map.
 */
function parseCbor(buffer) {
    const bytes = buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
    let offset = 0;

    function readLength(additional) {
        if (additional < 24) return additional;
        const count = additional === 24 ? 1 : additional === 25 ? 2 : additional === 26 ? 4 : 0;
        if (!count) throw new Error('CBOR tidak didukung');
        let value = 0;
        for (let i = 0; i < count; i++) value = (value << 8) | bytes[offset++];
        return value;
    }

    function readItem() {
        const initial = bytes[offset++];
        const major = initial >> 5;
        const additional = initial & 0x1f;
        if (major === 0) return readLength(additional);
        if (major === 1) return -1 - readLength(additional);
        if (major === 2) {
            const length = readLength(additional);
            const slice = bytes.slice(offset, offset + length);
            offset += length;
            return slice;
        }
        if (major === 3) {
            const length = readLength(additional);
            const slice = bytes.slice(offset, offset + length);
            offset += length;
            return new TextDecoder().decode(slice);
        }
        if (major === 4) {
            const length = readLength(additional);
            const arr = [];
            for (let i = 0; i < length; i++) arr.push(readItem());
            return arr;
        }
        if (major === 5) {
            const length = readLength(additional);
            const map = {};
            for (let i = 0; i < length; i++) {
                const key = readItem();
                map[key] = readItem();
            }
            return map;
        }
        throw new Error('CBOR tidak didukung');
    }

    return readItem();
}

function extractCoseFromAuthData(authDataBytes) {
    if (!(authDataBytes instanceof Uint8Array) || authDataBytes.length < 55) {
        return null;
    }
    const flags = authDataBytes[32];
    if ((flags & 0x40) === 0) {
        return null;
    }
    const credIdLen = (authDataBytes[53] << 8) | authDataBytes[54];
    const coseOffset = 55 + credIdLen;
    if (coseOffset >= authDataBytes.length) {
        return null;
    }
    return parseCbor(authDataBytes.slice(coseOffset));
}

class BiometricClient {
    constructor(baseUrl) {
        this.baseUrl = baseUrl.replace(/\/$/, '');
    }

    async _get(action, params = {}) {
        const qs = new URLSearchParams(Object.assign({}, params, { _t: Date.now() })).toString();
        const res = await fetch(`${this.baseUrl}?action=${action}&${qs}`, {
            cache: 'no-store',
            credentials: 'same-origin'
        });
        return res.json();
    }

    async _post(action, body) {
        const res = await fetch(`${this.baseUrl}?action=${action}`, {
            method: 'POST',
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        return res.json();
    }

    async status(entityType, entityId) {
        return this._get('biometric_status', { entity_type: entityType, entity_id: entityId });
    }

    /**
     * Pendaftaran WebAuthn: ambil challenge, buat credential discoverable,
     * kirim public key EC P-256 ke server.
     */
    async registerWebauthn(entityType, entityId, token) {
        const ch = await this._get('biometric_challenge');
        if (!ch.success) throw new Error(ch.message || 'Gagal mengambil challenge');

        const publicKey = {
            challenge: b64urlDecode(ch.challenge),
            rp: { name: 'UKMI Polmed Mentoring', id: location.hostname },
            user: {
                id: new TextEncoder().encode(entityType + ':' + entityId),
                name: entityType + ':' + entityId,
                displayName: entityType + ':' + entityId
            },
            pubKeyCredParams: [
                { alg: -7, type: 'public-key' },
                { alg: -257, type: 'public-key' },
                { alg: -8, type: 'public-key' }
            ],
            authenticatorSelection: {
                authenticatorAttachment: 'platform',
                userVerification: 'preferred',
                residentKey: 'required',
                requireResidentKey: true
            },
            timeout: 120000,
            excludeCredentials: []
        };

        const cred = await navigator.credentials.create({ publicKey });
        const payload = {
            entity_type: entityType,
            entity_id: entityId,
            method: 'webauthn',
            challenge: ch.challenge,
            credential_id: cred.rawId ? b64urlEncode(cred.rawId) : '',
            token: token || ''
        };

        if (cred.response.getAuthenticatorData) {
            try {
                payload.authenticator_data = b64urlEncode(new Uint8Array(cred.response.getAuthenticatorData()));
            } catch (e) {}
        }
        if (cred.response.attestationObject) {
            try {
                payload.attestation_object = b64urlEncode(new Uint8Array(cred.response.attestationObject));
            } catch (e) {}
        }

        // Jalur 1 (Utama): getPublicKey() mengembalikan SPKI DER (byte pertama 0x30)
        const publicKeyBuffer = cred.response.getPublicKey ? cred.response.getPublicKey() : null;
        if (publicKeyBuffer && publicKeyBuffer.byteLength > 0) {
            const spkiBytes = new Uint8Array(publicKeyBuffer);
            payload.public_key_spki = b64urlEncode(spkiBytes);
            if (cred.response.getPublicKeyAlgorithm) {
                payload.public_key_alg = cred.response.getPublicKeyAlgorithm();
            }
            if (spkiBytes.length >= 65 && spkiBytes[spkiBytes.length - 65] === 0x04) {
                payload.public_key_x = b64urlEncode(spkiBytes.slice(spkiBytes.length - 64, spkiBytes.length - 32));
                payload.public_key_y = b64urlEncode(spkiBytes.slice(spkiBytes.length - 32));
            }
            return this._post('biometric_register', payload);
        }

        // Jalur 2 (Fallback): ekstrak COSE_Key dari authenticatorData / attestationObject di client
        try {
            let authDataBytes = null;
            if (cred.response.getAuthenticatorData) {
                authDataBytes = new Uint8Array(cred.response.getAuthenticatorData());
            } else if (cred.response.attestationObject) {
                const att = parseCbor(cred.response.attestationObject);
                if (att && att.authData instanceof Uint8Array) {
                    authDataBytes = att.authData;
                }
            }
            const cose = extractCoseFromAuthData(authDataBytes);
            if (cose) {
                if (cose[1] === 2 && cose[-1] === 1 && (cose[-2] instanceof Uint8Array) && (cose[-3] instanceof Uint8Array)) {
                    payload.public_key_x = b64urlEncode(cose[-2]);
                    payload.public_key_y = b64urlEncode(cose[-3]);
                } else if (cose[1] === 3 && (cose[-1] instanceof Uint8Array) && (cose[-2] instanceof Uint8Array)) {
                    payload.rsa_n = b64urlEncode(cose[-1]);
                    payload.rsa_e = b64urlEncode(cose[-2]);
                } else if (cose[1] === 1 && cose[-1] === 6 && (cose[-2] instanceof Uint8Array)) {
                    payload.okp_x = b64urlEncode(cose[-2]);
                }
            }
        } catch (e) {
            // Biarkan server mengekstrak dari attestation_object / authenticator_data
        }

        return this._post('biometric_register', payload);
    }

    async registerPattern(entityType, entityId, pattern, token) {
        return this._post('biometric_register', {
            entity_type: entityType,
            entity_id: entityId,
            method: 'pattern',
            pattern: pattern,
            token: token || ''
        });
    }

    /**
     * Verifikasi WebAuthn menggunakan credential discoverable.
     * Identitas pemilik diambil dari userHandle yang ditandatangani perangkat.
     */
    async authenticateWebauthn(fallbackEntity = null) {
        const ch = await this._get('biometric_challenge');
        if (!ch.success) throw new Error(ch.message || 'Gagal mengambil challenge');

        const publicKey = {
            challenge: b64urlDecode(ch.challenge),
            rpId: location.hostname,
            userVerification: 'preferred',
            timeout: 60000
        };

        const cred = await navigator.credentials.get({ publicKey });
        const userHandle = cred.response.userHandle
            ? new TextDecoder().decode(new Uint8Array(cred.response.userHandle))
            : '';
        const separator = userHandle.indexOf(':');
        let entityType = separator >= 1 ? userHandle.slice(0, separator) : '';
        let entityId = separator >= 1 ? userHandle.slice(separator + 1) : '';
        if ((!entityType || !entityId) && fallbackEntity) {
            entityType = fallbackEntity.entity_type || '';
            entityId = fallbackEntity.entity_id || '';
        }

        return this._post('biometric_authenticate', {
            entity_type: entityType,
            entity_id: entityId,
            credential_id: cred.rawId ? b64urlEncode(cred.rawId) : '',
            method: 'webauthn',
            challenge: ch.challenge,
            authenticator_data: b64urlEncode(new Uint8Array(cred.response.authenticatorData)),
            client_data_json: b64urlEncode(new Uint8Array(cred.response.clientDataJSON)),
            signature: b64urlEncode(new Uint8Array(cred.response.signature))
        });
    }
}

window.BiometricClient = BiometricClient;
window.b64urlEncode = b64urlEncode;
window.b64urlDecode = b64urlDecode;
