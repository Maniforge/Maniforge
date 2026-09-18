import type { ResourceProps } from "@refinedev/core";

export const noteResource: ResourceProps = {
  name: "note",
  list: "/note",
  create: "/note/create",
  edit: "/note/edit/:id",
  show: "/note/show/:id",
  meta: { label: "Note", manifestCode: "note" },
};

export const noteFields = [
  { name: "title", type: "string", required: true },
  { name: "body", type: "string", required: false },
] as const;
