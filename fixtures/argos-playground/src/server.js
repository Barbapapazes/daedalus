import { createServer } from "node:http";
import { createTodo, deleteTodo, listTodos, updateTodo } from "./todos.js";

const MAX_BODY_BYTES = 16 * 1024;

function json(response, status, body) {
  response.writeHead(status, { "content-type": "application/json; charset=utf-8" });
  response.end(JSON.stringify(body));
}

async function readJson(request) {
  const chunks = [];
  let size = 0;
  for await (const chunk of request) {
    size += chunk.length;
    if (size > MAX_BODY_BYTES) throw new Error("Request body exceeds 16 KiB");
    chunks.push(chunk);
  }
  return JSON.parse(Buffer.concat(chunks).toString("utf8"));
}

const server = createServer(async (request, response) => {
  const pathname = new URL(request.url, "http://localhost").pathname;
  if (request.method === "GET" && pathname === "/health") {
    return json(response, 200, { ok: true });
  }
  if (request.method === "GET" && pathname === "/todos") {
    return json(response, 200, { todos: listTodos() });
  }
  if (request.method === "POST" && pathname === "/todos") {
    try {
      const body = await readJson(request);
      return json(response, 201, createTodo(body?.title));
    } catch (error) {
      return json(response, 400, { error: error.message });
    }
  }
  const match = pathname.match(/^\/todos\/([0-9]+)$/);
  if (request.method === "PATCH" && match) {
    try {
      const todo = updateTodo(match[1], await readJson(request));
      return json(response, todo ? 200 : 404, todo ?? { error: "Todo not found" });
    } catch (error) {
      return json(response, 400, { error: error.message });
    }
  }
  if (request.method === "DELETE" && match) {
    if (!deleteTodo(match[1])) return json(response, 404, { error: "Todo not found" });
    response.writeHead(204);
    return response.end();
  }
  return json(response, 404, { error: "Route not found" });
});

const port = Number(process.env.PORT ?? 3000);
server.listen(port, "127.0.0.1", () => {
  console.log("Todo API listening on http://127.0.0.1:" + server.address().port);
});
