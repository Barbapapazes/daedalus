// Todos are process-local: restarting the server resets the list.
const todos = new Map([
  ["1", { id: "1", title: "Try the todo app", completed: false }],
  ["2", { id: "2", title: "Start the API", completed: true }],
]);
let nextId = 3;

function validateTitle(title) {
  if (typeof title !== "string" || !title.trim() || title.trim().length > 200) {
    throw new Error("title must contain 1–200 characters");
  }
  return title.trim();
}

export function listTodos() {
  return [...todos.values()].map(todo => ({ ...todo }));
}

export function createTodo(title) {
  const validatedTitle = validateTitle(title);
  const todo = { id: String(nextId++), title: validatedTitle, completed: false };
  todos.set(todo.id, todo);
  return { ...todo };
}

export function updateTodo(id, changes) {
  const todo = todos.get(id);
  if (!todo) return null;
  if (!changes || typeof changes !== "object" || Array.isArray(changes)) {
    throw new Error("Provide a title or completed value");
  }
  const keys = Object.keys(changes);
  if (!keys.length || keys.some(key => key !== "title" && key !== "completed")) {
    throw new Error("Only title and completed can be updated");
  }
  const title = "title" in changes ? validateTitle(changes.title) : todo.title;
  if ("completed" in changes && typeof changes.completed !== "boolean") {
    throw new Error("completed must be a boolean");
  }
  todo.title = title;
  if ("completed" in changes) todo.completed = changes.completed;
  return { ...todo };
}

export function deleteTodo(id) {
  return todos.delete(id);
}
