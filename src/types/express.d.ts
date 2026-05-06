declare module "express" {
  export type Request = any;
  export type Response = any;
  export type NextFunction = any;

  export interface RouterInstance {
    get: any;
    post: any;
    put: any;
    patch: any;
    delete: any;
    use: any;
  }

  export function Router(): RouterInstance;
}
