-- AutoMax-Pecas | 7 sequences
-- Para um schema NOVO, apos executar 01_tabelas.sql.
-- As quatro ultimas definicoes vieram do script da migracao original.
-- As tres primeiras ja existiam antes da migracao; seus parametros originais
-- nao foram recuperados. START WITH 1 e um padrao para instalacao limpa,
-- NAO e uma transcricao dos valores atuais do banco em producao.
-- NUNCA executar sobre um schema ja populado.

CREATE SEQUENCE SEQ_AUTOMAX_CLIENTE START WITH 1 INCREMENT BY 1 NOCYCLE;
CREATE SEQUENCE SEQ_AUTOMAX_VEICULO START WITH 1 INCREMENT BY 1 NOCYCLE;
CREATE SEQUENCE SEQ_AUTOMAX_PRODUTO START WITH 1 INCREMENT BY 1 NOCYCLE;

CREATE SEQUENCE SEQ_AUTOMAX_ESTOQUE START WITH 1 INCREMENT BY 1 NOCYCLE;
CREATE SEQUENCE SEQ_AUTOMAX_USUARIO START WITH 1 INCREMENT BY 1 NOCYCLE;
CREATE SEQUENCE SEQ_AUTOMAX_ORCAMENTO START WITH 1 INCREMENT BY 1 NOCYCLE;
CREATE SEQUENCE SEQ_AUTOMAX_ITEM_ORCAMENTO START WITH 1 INCREMENT BY 1 NOCYCLE;
