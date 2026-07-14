require("dotenv").config();
const mysql = require("mysql2/promise");
const fs = require("fs");
const path = require("path");

const dbConfig = {
  host: process.env.DB_HOST || "localhost",
  port: process.env.DB_PORT ? Number(process.env.DB_PORT) : 3306,
  user: process.env.DB_USER || "root",
  password: process.env.DB_PASSWORD || "",
};

async function main() {
  const schemaPath = path.join(__dirname, "schema.sql");
  const schema = fs.readFileSync(schemaPath, "utf8");

  const connection = await mysql.createConnection({
    ...dbConfig,
    multipleStatements: true,
  });

  console.log("Conectando a la base de datos...");
  await connection.query(schema);
  console.log("Esquema importado correctamente.");
  await connection.end();
}

main().catch((err) => {
  console.error("Error importando el esquema de base de datos:", err.message);
  process.exit(1);
});
