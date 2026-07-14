const { Client, TopicMessageSubmitTransaction, PrivateKey } = require("@hashgraph/sdk");

function isConfigured() {
  return Boolean(
    process.env.HEDERA_ACCOUNT_ID &&
    process.env.HEDERA_PRIVATE_KEY &&
    process.env.HEDERA_TOPIC_ID
  );
}

function getClient() {
  if (!isConfigured()) {
    return null;
  }

  const client = Client.forName(process.env.HEDERA_NETWORK || "testnet");
  
  let privateKey;
  const privateKeyStr = process.env.HEDERA_PRIVATE_KEY;
  if (privateKeyStr.startsWith("0x")) {
    const hexStr = privateKeyStr.slice(2);
    
    // Intenta ED25519 primero
    try {
      privateKey = PrivateKey.fromStringED25519(hexStr);
    } catch (e1) {
      // Si falla, intenta ECDSA
      try {
        privateKey = PrivateKey.fromStringECDSA(hexStr);
      } catch (e2) {
        throw new Error("No se pudo parsear la clave privada de Hedera");
      }
    }
  } else if (privateKeyStr.length > 100) {
    // Probablemente sea DER en hex
    try {
      privateKey = PrivateKey.fromStringDer(privateKeyStr);
    } catch (e) {
      privateKey = PrivateKey.fromString(privateKeyStr);
    }
  } else {
    privateKey = PrivateKey.fromString(privateKeyStr);
  }
  
  client.setOperator(process.env.HEDERA_ACCOUNT_ID, privateKey);
  return client;
}

async function submitAssignmentMessage(payload) {
  const client = getClient();
  if (!client) {
    throw new Error(
      "Hedera no está configurado. Define HEDERA_ACCOUNT_ID, HEDERA_PRIVATE_KEY y HEDERA_TOPIC_ID."
    );
  }

  const response = await new TopicMessageSubmitTransaction({
    topicId: process.env.HEDERA_TOPIC_ID,
    message: JSON.stringify(payload),
  }).execute(client);

  const receipt = await response.getReceipt(client);
  const txId = response.transactionId?.toString();
  const txHash = response.transactionHash?.toString("hex");
  const fallbackId = receipt.transactionId?.toString();

  if (txId) {
    return txId;
  }
  if (fallbackId) {
    return fallbackId;
  }
  if (txHash) {
    console.warn("Hedera no devolvió transactionId, usando transactionHash como identificador", txHash);
    return txHash;
  }

  console.error("Hedera no devolvió transactionId ni transactionHash", {
    response: response.toString?.(),
    receipt: receipt.toString?.(),
    receiptStatus: receipt.status?.toString?.(),
  });
  throw new Error("No se pudo obtener el identificador de transacción de Hedera");
}

module.exports = {
  isConfigured,
  submitAssignmentMessage,
};
