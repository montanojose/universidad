require("dotenv").config();
const { Client, TopicCreateTransaction, PrivateKey } = require("@hashgraph/sdk");

function getClient() {
  const accountId = process.env.HEDERA_ACCOUNT_ID;
  const privateKeyStr = process.env.HEDERA_PRIVATE_KEY;
  const network = process.env.HEDERA_NETWORK || "testnet";

  if (!accountId || !privateKeyStr) {
    throw new Error("Debes configurar HEDERA_ACCOUNT_ID y HEDERA_PRIVATE_KEY en .env");
  }

  const client = Client.forName(network);
  
  let privateKey;
  try {
    if (privateKeyStr.startsWith("0x")) {
      const hexStr = privateKeyStr.slice(2);
      
      // Intenta ED25519 primero
      try {
        privateKey = PrivateKey.fromStringED25519(hexStr);
        console.log("Clave privada parseada como ED25519");
      } catch (e1) {
        // Si falla, intenta ECDSA
        try {
          privateKey = PrivateKey.fromStringECDSA(hexStr);
          console.log("Clave privada parseada como ECDSA");
        } catch (e2) {
          throw new Error("No se pudo parsear la clave ni como ED25519 ni como ECDSA");
        }
      }
    } else if (privateKeyStr.length > 100) {
      // Probablemente sea DER en hex
      try {
        privateKey = PrivateKey.fromStringDer(privateKeyStr);
        console.log("Clave privada parseada como DER");
      } catch (e) {
        privateKey = PrivateKey.fromString(privateKeyStr);
        console.log("Clave privada parseada como formato estándar");
      }
    } else {
      privateKey = PrivateKey.fromString(privateKeyStr);
      console.log("Clave privada parseada como formato estándar");
    }
  } catch (err) {
    console.error("Error al parsear clave privada:", err.message);
    throw new Error("La clave privada no está en formato válido. Verifica que sea hex o DER.");
  }
  
  client.setOperator(accountId, privateKey);
  return client;
}

async function main() {
  const client = getClient();
  console.log("Creando topic en Hedera... Esto puede tardar unos segundos.");

  const tx = await new TopicCreateTransaction().execute(client);
  const receipt = await tx.getReceipt(client);
  const topicId = receipt.topicId.toString();

  console.log("Topic creado:", topicId);
  console.log("Agregá esta línea a backend/.env:");
  console.log(`HEDERA_TOPIC_ID=${topicId}`);
}

main().catch((err) => {
  console.error("Error creando topic en Hedera:", err.message || err);
  process.exit(1);
});
