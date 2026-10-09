'use strict';

class IdempotenciaStore {
  constructor(ttlMs, maxEntries) {
    this.ttlMs = ttlMs;
    this.maxEntries = maxEntries;
    this.entradas = new Map();
  }

  _purgarExpirados() {
    const agora = Date.now();
    for (const [identificador, entrada] of this.entradas) {
      if (agora - entrada.timestamp > this.ttlMs) {
        this.entradas.delete(identificador);
      }
    }
  }

  obterStatus(identificador) {
    this._purgarExpirados();
    const entrada = this.entradas.get(identificador);
    return entrada ? entrada.status : null;
  }

  _registrar(identificador, status) {
    this._purgarExpirados();

    if (!this.entradas.has(identificador) && this.entradas.size >= this.maxEntries) {
      const maisAntigo = this.entradas.keys().next().value;
      if (maisAntigo !== undefined) {
        this.entradas.delete(maisAntigo);
      }
    }

    this.entradas.set(identificador, { timestamp: Date.now(), status });
  }

  marcarProcessado(identificador) {
    this._registrar(identificador, 'impresso');
  }

  marcarIndeterminado(identificador) {
    this._registrar(identificador, 'indeterminado');
  }
}

module.exports = { IdempotenciaStore };
