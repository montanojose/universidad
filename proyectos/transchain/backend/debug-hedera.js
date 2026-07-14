require('dotenv').config();
const { Client, TopicMessageSubmitTransaction, PrivateKey } = require('@hashgraph/sdk');

(async () => {
  try {
    const privateKeyStr = process.env.HEDERA_PRIVATE_KEY;
    let privateKey;

    if (privateKeyStr.startsWith('0x')) {
      const hexStr = privateKeyStr.slice(2);
      try {
        privateKey = PrivateKey.fromStringED25519(hexStr);
        console.log('Parsed ED25519');
      } catch (e1) {
        privateKey = PrivateKey.fromStringECDSA(hexStr);
        console.log('Parsed ECDSA');
      }
    } else if (privateKeyStr.length > 100) {
      try {
        privateKey = PrivateKey.fromStringDer(privateKeyStr);
        console.log('Parsed DER');
      } catch (e) {
        privateKey = PrivateKey.fromString(privateKeyStr);
        console.log('Parsed standard string');
      }
    } else {
      privateKey = PrivateKey.fromString(privateKeyStr);
      console.log('Parsed standard string');
    }

    const client = Client.forName(process.env.HEDERA_NETWORK || 'testnet');
    client.setOperator(process.env.HEDERA_ACCOUNT_ID, privateKey);

    const tx = new TopicMessageSubmitTransaction({
      topicId: process.env.HEDERA_TOPIC_ID,
      message: 'debug-message'
    });
    const response = await tx.execute(client);
    console.log('response', response);
    console.log('response transactionId', response.transactionId);
    const receipt = await response.getReceipt(client);
    console.log('receipt', receipt);
    console.log('receipt transactionId', receipt.transactionId);
    console.log('receipt status', receipt.status?.toString?.());
  } catch (err) {
    console.error('ERROR', err);
  }
})();