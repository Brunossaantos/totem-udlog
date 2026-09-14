'use strict';

/**
 * Store de idempotencia em memoria (o servico nao tem banco proprio e nao
 * precisa: reinicio do servico = reinicio do totem = job novo sempre
 * reenviado pelo backend PHP se necessario). Guarda, por identificador de
 * job (enviado pelo backend PHP a cada impressao), o STATUS conhecido
 * daquele job:
 *  - 'impresso': o job terminou com sucesso -- reenvio do mesmo
 *    identificador e ignorado (idempotencia classica).
 *  - 'indeterminado': o job estourou o timeout configurado e o processo
 *    de impressao foi encerrado a forca -- nao sabemos se a etiqueta saiu
 *    fisicamente ou nao. O identificador continua bloqueado (protecao de
 *    idempotencia mantida -- nunca reprocessa o mesmo job), mas a resposta
 *    a uma nova tentativa com o MESMO identificador e diferente de
 *    'ja_impresso', para o front-end poder orientar o usuario a gerar uma
 *    etiqueta nova em vez de simplesmente repetir a mesma automaticamente.
 *
 * ttlMs: por quanto tempo um identificador "usado" (em qualquer status) e
 * lembrado.
 * maxEntries: limite de entradas em memoria -- ao atingir o limite, a
 * entrada mais antiga e descartada (mesmo que o TTL dela ainda nao tenha
 * vencido), para nunca crescer sem limite.
 */
class IdempotenciaStore {
  constructor(ttlMs, maxEntries) {
    this.ttlMs = ttlMs;
    this.maxEntries = maxEntries;
    /** @type {Map<string, { timestamp: number, status: 'impresso' | 'indeterminado' }>} */
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

  /**
   * @param {string} identificador
   * @returns {'impresso' | 'indeterminado' | null} status conhecido deste
   * identificador (dentro do TTL), ou null se nunca visto / ja expirado.
   */
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

  /**
   * Marca um identificador como ja impresso com sucesso.
   * @param {string} identificador
   */
  marcarProcessado(identificador) {
    this._registrar(identificador, 'impresso');
  }

  /**
   * Marca um identificador como indeterminado (timeout no processo de
   * impressao -- resultado real desconhecido).
   * @param {string} identificador
   */
  marcarIndeterminado(identificador) {
    this._registrar(identificador, 'indeterminado');
  }
}

module.exports = { IdempotenciaStore };
