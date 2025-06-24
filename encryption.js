// encryption.js
// Encrypt Resumable.js chunks using Web Crypto API

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
 * Encrypt a Blob (usually a chunk) and return a Blob containing
 * optional salt + IV + ciphertext.
 * @param {Blob} blob
 * @param {CryptoKey} key
 * @param {Uint8Array} salt
 * @param {boolean} includeSalt
 * @returns {Promise<Blob>}
 */
async function encryptBlob(blob, key, salt, includeSalt = false) {
  const buffer = await blob.arrayBuffer();
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const cipherBuf = await crypto.subtle.encrypt(
    { name: 'AES-GCM', iv },
    key,
    buffer
  );
  const extra = includeSalt ? salt.byteLength : 0;
  const out = new Uint8Array(extra + iv.byteLength + cipherBuf.byteLength);
  let pos = 0;
  if (includeSalt) { out.set(salt, pos); pos += salt.byteLength; }
  out.set(iv, pos); pos += iv.byteLength;
  out.set(new Uint8Array(cipherBuf), pos);
  return new Blob([out], { type: 'application/octet-stream' });
}

/**
 * Patch a Resumable file object so that slice() returns encrypted chunks
 * if available.
 * @param {ResumableFile} resumableFile
 */
function patchFileSlice(resumableFile) {
  const file = resumableFile.file;
  if (file.__encryptedPatch) return;
  file.__encryptedPatch = true;
  file.__origSlice = file.slice.bind(file);
  file.__encryptedChunks = {};
  file.slice = function(start, end, type) {
    const key = start + '-' + end;
    if (file.__encryptedChunks[key]) return file.__encryptedChunks[key];
    return file.__origSlice(start, end, type);
  };
}

/**
 * Install chunk-based encryption on a Resumable.js instance.
 * Each chunk is encrypted just before upload.
 * @param {Resumable} r
 * @param {string} password
 */
function setupEncryption(r, password) {
  if (r.__encryptionSetup) return r.__encryptionSetup;

  r.__encryptionSetup = deriveKey(password).then(({ key, salt }) => {
    r.__encryption = { key, salt };

    const origQuery = r.opts.query;
    r.opts.query = function() {
      const q = typeof origQuery === 'function' ? origQuery() : (origQuery || {});
      q.encrypted = 1;
      q.salt = btoa(String.fromCharCode(...salt));
      return q;
    };

    const origPreprocess = r.opts.preprocess;
    r.opts.preprocess = function(chunk) {
      patchFileSlice(chunk.fileObj);
      const includeSalt = chunk.offset === 0;
      const origSlice = chunk.fileObj.file.__origSlice;
      const blob = origSlice.call(chunk.fileObj.file, chunk.startByte, chunk.endByte);
      encryptBlob(blob, key, salt, includeSalt)
        .then(encBlob => {
          const mapKey = chunk.startByte + '-' + chunk.endByte;
          chunk.fileObj.file.__encryptedChunks[mapKey] = encBlob;
          if (typeof origPreprocess === 'function') {
            origPreprocess(chunk);
          } else {
            chunk.preprocessFinished();
          }
        })
        .catch(err => {
          console.error('Chunk encryption failed:', err);
          if (typeof origPreprocess === 'function') {
            origPreprocess(chunk);
          } else {
            chunk.preprocessFinished();
          }
        });
    };
  }).catch(err => {
    console.error('Key derivation failed:', err);
  });

  return r.__encryptionSetup;
}

if (typeof module !== 'undefined') {
  module.exports = { deriveKey, encryptBlob, setupEncryption };
} else {
  window.deriveKey = deriveKey;
  window.encryptBlob = encryptBlob;
  window.setupEncryption = setupEncryption;
}
