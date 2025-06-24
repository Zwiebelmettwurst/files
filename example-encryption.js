// Example helper to encrypt Resumable.js chunks with WebCrypto
// Call setupEncryption(resumable, password) before starting the upload.

async function deriveKey(password) {
  const enc = new TextEncoder();
  const keyMaterial = await crypto.subtle.importKey(
    'raw', enc.encode(password), {name: 'PBKDF2'}, false, ['deriveKey']
  );
  const salt = enc.encode('static-salt'); // In production use a random salt stored with the file
  return crypto.subtle.deriveKey(
    {name: 'PBKDF2', salt, iterations: 100000, hash: 'SHA-256'},
    keyMaterial,
    {name: 'AES-GCM', length: 256},
    false,
    ['encrypt']
  );
}

function setupEncryption(resumable, password) {
  const origQuery = resumable.opts.query;
  return deriveKey(password).then(key => {
    resumable.opts.query = function() {
      let base = typeof origQuery === 'function' ? origQuery() : origQuery || {};
      base.encrypted = 1;
      return base;
    };
    resumable.opts.preprocess = async function(chunk) {
      const buf = await chunk.file.slice(chunk.startByte, chunk.endByte).arrayBuffer();
      const iv = crypto.getRandomValues(new Uint8Array(12));
      const cipher = await crypto.subtle.encrypt({name: 'AES-GCM', iv}, key, buf);
      chunk.data = new Blob([iv, new Uint8Array(cipher)]);
      chunk.size = chunk.data.size;
      chunk.preprocessFinished();
    };
  });
}

if (typeof module !== 'undefined') module.exports = { setupEncryption };
