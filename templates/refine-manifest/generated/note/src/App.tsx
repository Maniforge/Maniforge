import { Refine } from "@refinedev/core";
import routerProvider from "@refinedev/react-router-v6";
import { BrowserRouter, Routes, Route, Outlet } from "react-router-dom";
import { manifestDataProvider } from "./providers/manifestDataProvider";
import { noteResource } from "./resources/note";

/** Сгенерировано Maniforge manifest-refine-gen. Токен: localStorage maniforge_admin_access_token */
export default function App() {
  return (
    <BrowserRouter>
      <Refine
        routerProvider={routerProvider}
        dataProvider={manifestDataProvider}
        resources={[{noteResource}]}
        options={{ syncWithLocation: true }}
      >
        <Routes>
          <Route element={<Outlet />}>
            <Route index element={<div>Note — выберите resource в меню Refine</div>} />
          </Route>
        </Routes>
      </Refine>
    </BrowserRouter>
  );
}
