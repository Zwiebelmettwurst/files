// encryption.js
// Encrypt entire files added to Resumable.js using Web Crypto API

/**
 * Derive an AES-GCM key and random salt from a password using PBKDF2.
 * @param {string} password
 * @returns {Promise<{key: CryptoKey, salt: Uint8Array}>}
 */
async function deriveKey(password) {
  const enc = new TextEncoder();
  const salt = crypto.getRandomValues(new Uint8Array(16));
  const keyMaterial = await crypto.subtle.importKey(
      'raw', enc.encode(password), 'PBKDF2', false, ['deriveKey']
  );
  const key = await crypto.subtle.deriveKey(
      { name: 'PBKDF2', salt, iterations: 200000, hash: 'SHA-256' },
      keyMaterial,
      { name: 'AES-GCM', length: 256 },
      false,
      ['encrypt']
  );
  return { key, salt };
}

/**
 * Encrypts a File/Blob entirely: outputs a new File containing salt + IV + ciphertext.
 * @param {File} file
 * @param {CryptoKey} key
 * @param {Uint8Array} salt
 * @returns {Promise<File>}
 */
async function encryptFile(file, key, salt) {
  const buffer = await file.arrayBuffer();
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const cipherBuf = await crypto.subtle.encrypt(
      { name: 'AES-GCM', iv },
      key,
      buffer
  );
  // Construct combined payload: salt || iv || ciphertext
  const out = new Uint8Array(salt.byteLength + iv.byteLength + cipherBuf.byteLength);
  out.set(salt, 0);
  out.set(iv, salt.byteLength);
  out.set(new Uint8Array(cipherBuf), salt.byteLength + iv.byteLength);
  return new File([out], file.name, { type: 'application/octet-stream' });
}

/**
 * Install encryption on a Resumable.js instance.
 * - Original files are encrypted fully before upload.
 * - Encrypted files are marked with isEncrypted = true.
 * - After encryption, upload is triggered automatically.
 * @param {Resumable} r - Resumable.js instance
 * @param {string} password - Password for encryption
 */
function setupEncryption(r, password) {
  // avoid multiple setups on the same instance
  if (r.__encryptionSetup) return r.__encryptionSetup;

  r.__encryptionSetup = deriveKey(password).then(({ key, salt }) => {
    // store key/salt on instance for later reuse
    r.__encryption = { key, salt };

    // Inject metadata: encrypted flag + base64 salt
    const origQuery = r.opts.query;
    r.opts.query = function() {
      const q = typeof origQuery === 'function' ? origQuery() : (origQuery || {});
      q.encrypted = 1;
      q.salt = btoa(String.fromCharCode(...salt));
      return q;
    };

    async function encryptAndReplace(file) {
      if (file.isEncrypted || (file.file && file.file.isEncrypted)) return; // already processed
      try {
        const encryptedFile = await encryptFile(file.file, key, salt);
        encryptedFile.isEncrypted = true;
        r.removeFile(file);
        const added = r.addFile(encryptedFile);
        if (added) added.isEncrypted = true;
      } catch (err) {
        console.error('File encryption failed:', err);
        r.upload();
      }
    }

    // encrypt already added files (if any)
    r.files.slice().forEach(encryptAndReplace);

    // encrypt future files
    r.on('fileAdded', encryptAndReplace);
  }).catch(err => {
    console.error('Key derivation failed:', err);
  });

  return r.__encryptionSetup;
}

// Export for CommonJS or attach globally
if (typeof module !== 'undefined') {
  module.exports = { deriveKey, encryptFile, setupEncryption };
} else {
  window.deriveKey = deriveKey;
  window.encryptFile = encryptFile;
  window.setupEncryption = setupEncryption;
}
